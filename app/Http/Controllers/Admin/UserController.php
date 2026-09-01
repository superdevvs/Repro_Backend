<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PhotographerEquipment;
use App\Models\PhotographerEquipmentPhoto;
use App\Models\ServiceGroup;
use App\Models\User;
use App\Models\AccountLink;
use App\Models\UserActivityLog;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\MailService;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\DashboardOnboardingService;
use App\Services\Users\EmailHealthService;
use App\Services\Users\AccountCreatedNotificationService;
use App\Services\Users\PhoneNumberChangedNotificationService;
use App\Services\Users\PhotographerAddressPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;


class UserController extends Controller
{
    private const PRIMARY_SUPERADMIN_EMAIL = 'aj@reprophotos.com';

    public function __construct(
        private readonly EmailHealthService $emailHealthService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (!$this->userHasAnyRole($user, ['admin', 'superadmin', 'editing_manager', 'salesRep'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $primarySuperAdmin = User::whereRaw('LOWER(email) = ?', [self::PRIMARY_SUPERADMIN_EMAIL])->first();
        if ($primarySuperAdmin && $primarySuperAdmin->role === 'superadmin') {
            $this->demoteOtherSuperAdmins($primarySuperAdmin);
        }

        // Optimize: Eager load relationships and batch queries
        if ($this->isSalesRepUser($user)) {
            $users = $this->getSalesRepVisibleAccounts($user);
        } else {
            $usersQuery = User::query();
            if ($this->serviceGroupsFeatureAvailable()) {
                $usersQuery->with('serviceGroups');
            }

            $users = $usersQuery->get();
        }

        if ($users->isEmpty()) {
            return response()->json(['users' => []]);
        }

        $useLightPayload = $request->boolean('light');
        if ($useLightPayload) {
            $emptyLinks = collect();
            $emptyCounts = collect();
            $emptyTotals = collect();

            $users = $users->map(function (User $record) use ($user, $emptyLinks, $emptyCounts, $emptyTotals) {
                return $this->presentUserForViewerOptimized($record, $user, $emptyLinks, $emptyCounts, $emptyTotals);
            });

            return response()->json(['users' => $users]);
        }
        
        // Pre-load all account links in one query
        $allAccountIds = $users->pluck('id')->toArray();
        $allLinksCollection = \App\Models\AccountLink::where(function($query) use ($allAccountIds) {
            $query->whereIn('main_account_id', $allAccountIds)
                  ->orWhereIn('linked_account_id', $allAccountIds);
        })
            ->active()
            ->with(['mainAccount', 'linkedAccount'])
            ->get();
        
        // Group links by both main and linked account IDs for quick lookup
        $allLinks = collect();
        foreach ($allLinksCollection as $link) {
            $allLinks->push(['account_id' => $link->main_account_id, 'link' => $link]);
            $allLinks->push(['account_id' => $link->linked_account_id, 'link' => $link]);
        }
        $allLinks = $allLinks->groupBy('account_id')->map(function($group) {
            return $group->pluck('link');
        });
        
        // Pre-load shoot counts for all users in one query
        $shootCounts = \App\Models\Shoot::whereIn('client_id', $allAccountIds)
            ->selectRaw('client_id, COUNT(*) as count')
            ->groupBy('client_id')
            ->pluck('count', 'client_id');
        
        // Pre-load total spent for all users in one query
        $totalSpent = \App\Models\Shoot::whereIn('client_id', $allAccountIds)
            ->selectRaw('client_id, SUM(total_quote) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        // Map users with pre-loaded data
        $users = $users->map(function (User $record) use ($user, $allLinks, $shootCounts, $totalSpent) {
            return $this->presentUserForViewerOptimized($record, $user, $allLinks, $shootCounts, $totalSpent);
        });

        return response()->json(['users' => $users]);
    }

    public function show(Request $request, $id)
    {
        $viewer = $request->user();

        if (!$this->userHasAnyRole($viewer, ['admin', 'superadmin', 'editing_manager', 'salesRep'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = User::query();
        if ($this->serviceGroupsFeatureAvailable()) {
            $query->with('serviceGroups');
        }

        $user = $query->findOrFail($id);

        if ($this->isSalesRepUser($viewer) && !$this->salesRepCanAccessAccount($viewer, $user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $payload = $this->presentUserForViewer($user, $viewer);
        $lastLoginAt = $this->getUserLastLoginAt($user);

        $payload['createdAt'] = optional($user->created_at)?->toIso8601String();
        $payload['updatedAt'] = optional($user->updated_at)?->toIso8601String();
        $payload['lastLogin'] = $lastLoginAt?->toIso8601String();
        $payload['activityLog'] = $this->getUserActivityTimeline($user, (int) $request->integer('activity_limit', 100));

        return response()->json([
            'user' => $payload,
        ]);
    }

     public function store(Request $request)
    {
        $admin = $request->user();

        if (!$this->userHasAnyRole($admin, ['admin', 'superadmin', 'salesRep'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'email_warning_override' => 'nullable|boolean',
            'timezone' => 'nullable|string|max:64',
            'username' => 'nullable|string|unique:users',
            'phone_number' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip' => 'nullable|string|max:20',
            'license_number' => 'nullable|string|max:100',
            'company_notes' => 'nullable|string',
            'shoot_cc_emails' => 'nullable|array',
            'shoot_cc_emails.*' => 'email',
            'clear_shoot_cc_emails' => 'nullable|boolean',
            'client_discount_type' => 'nullable|in:fixed,percent',
            'client_discount_value' => 'nullable|numeric|min:0',
            'role' => 'required|in:superadmin,admin,editing_manager,client,photographer,editor,salesRep',
            'account_status' => 'nullable|in:active,inactive,suspended',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|image|max:2048',
            'metadata' => 'nullable',
            'preferences' => 'sometimes|array',
            'preferences.notificationEmail' => 'nullable|boolean',
            'created_by_name' => 'nullable|string|max:255',
            'created_by_id' => 'nullable|integer|exists:users,id',
            'pilotLicenseFile' => 'nullable|string|url',
            'pilotLicenseFileName' => 'nullable|string|max:255',
            'insuranceNumber' => 'nullable|string|max:255',
            'insuranceFile' => 'nullable|string|url',
            'insuranceFileName' => 'nullable|string|max:255',
            'specialties' => 'nullable|string',
            'editing_capabilities' => 'nullable|string',
            'equipments' => 'nullable',
            'existing_equipment_ids' => 'nullable|array',
            'existing_equipment_ids.*' => 'integer',
            'equipment_reference_photos' => 'nullable|array',
            'equipment_reference_photos.*' => 'nullable|array',
            'equipment_reference_photos.*.*' => 'file|image|max:10240',
        ];

        if ($this->serviceGroupsFeatureAvailable()) {
            $rules['service_group_ids'] = 'nullable|array';
            $rules['service_group_ids.*'] = 'integer|exists:service_groups,id';
        }

        $validated = $request->validate($rules);
        $equipmentPayload = $this->normalizePhotographerEquipmentPayload($request->input('equipments'));
        $existingEquipmentIds = collect($request->input('existing_equipment_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (($validated['role'] ?? null) === 'photographer' && ($equipmentPayload !== [] || $existingEquipmentIds !== []) && !$this->photographerEquipmentTablesReady()) {
            return response()->json([
                'message' => 'Photographer equipment tables are not available yet. Run backend migrations before creating accounts with equipment.',
                'setup_required' => 'php artisan migrate',
            ], 503);
        }

        $requestedRole = $validated['role'] ?? null;
        if ($this->isSalesRepUser($admin) && !in_array($requestedRole, $this->salesRepCreatableRoles(), true)) {
            return response()->json([
                'message' => 'Sales reps can only create client accounts.',
            ], 403);
        }
        if ($requestedRole !== null) {
            $requestedEmail = strtolower((string) ($validated['email'] ?? ''));
            if ($requestedEmail === self::PRIMARY_SUPERADMIN_EMAIL && $requestedRole !== 'superadmin') {
                return response()->json([
                    'message' => 'The primary superadmin must remain aj@reprophotos.com.',
                ], 422);
            }
            if ($requestedRole === 'superadmin' && $requestedEmail !== self::PRIMARY_SUPERADMIN_EMAIL) {
                return response()->json([
                    'message' => 'Only aj@reprophotos.com can be a superadmin.',
                ], 422);
            }
        }

        $emailHealthMutation = $this->resolveEmailHealthMutation($validated);
        if ($emailHealthMutation['response'] !== null) {
            return $emailHealthMutation['response'];
        }

        unset($validated['email_warning_override']);
        unset($validated['equipments'], $validated['existing_equipment_ids'], $validated['equipment_reference_photos']);
        $validated = array_merge($validated, $emailHealthMutation['attributes']);

        $serviceGroupIdsProvided = array_key_exists('service_group_ids', $validated);
        $serviceGroupIds = collect($validated['service_group_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        unset($validated['service_group_ids']);

        if (array_key_exists('phone_number', $validated)) {
            $validated['phonenumber'] = $validated['phone_number'];
            unset($validated['phone_number']);
        }

        if (($validated['clear_shoot_cc_emails'] ?? false) && !array_key_exists('shoot_cc_emails', $validated)) {
            $validated['shoot_cc_emails'] = [];
        }
        unset($validated['clear_shoot_cc_emails']);

        $validated['shoot_cc_emails'] = $this->sanitizeShootCcEmails($validated['shoot_cc_emails'] ?? []);
        [$validated['client_discount_type'], $validated['client_discount_value']] = $this->normalizeClientDiscount(
            $validated['role'] ?? null,
            $validated['client_discount_type'] ?? null,
            $validated['client_discount_value'] ?? null
        );

        // Ensure username exists even if frontend omits it
        if (empty($validated['username'] ?? null)) {
            $validated['username'] = $this->generateUniqueUsername(
                $validated['email'] ?? ($validated['name'] ?? 'user')
            );
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            $validated['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        // Generate a temporary password or leave it blank
        $validated['password'] = Hash::make('defaultpassword'); // or generate one

        if ($request->has('metadata')) {
            $metadata = $request->input('metadata');
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }
            if (is_array($metadata)) {
                $validated['metadata'] = $this->filterMetadataForWriter($metadata, $admin);
            }
        } else {
            $validated['metadata'] = [];
        }

        if ($this->isSalesRepUser($admin)) {
            $validated['created_by_id'] = (int) $admin->id;
            $validated['created_by_name'] = $admin->name;
            unset($validated['account_status']);

            if (($validated['role'] ?? null) === 'client') {
                $validated['metadata'] = array_merge($validated['metadata'] ?? [], [
                    'accountRepId' => (string) $admin->id,
                    'accountRep' => $admin->name,
                    'account_rep_id' => (int) $admin->id,
                    'account_rep' => $admin->name,
                ]);
            }
        }

        if (array_key_exists('preferences', $validated) && is_array($validated['preferences'])) {
            $metadata = is_array($validated['metadata'] ?? null)
                ? $validated['metadata']
                : [];
            $existingPreferences = is_array($metadata['preferences'] ?? null) ? $metadata['preferences'] : [];
            $metadata['preferences'] = array_replace($existingPreferences, $validated['preferences']);
            $validated['metadata'] = $metadata;
            unset($validated['preferences']);
        }

        // Add photographer-specific fields to metadata
        if ($validated['role'] === 'photographer') {
            $photographerData = [];
            
            if ($request->has('pilotLicenseFile')) {
                $photographerData['pilotLicenseFile'] = $request->input('pilotLicenseFile');
            }
            if ($request->has('pilotLicenseFileName')) {
                $photographerData['pilotLicenseFileName'] = $request->input('pilotLicenseFileName');
            }
            if ($request->has('insuranceNumber')) {
                $photographerData['insuranceNumber'] = $request->input('insuranceNumber');
            }
            if ($request->has('insuranceFile')) {
                $photographerData['insuranceFile'] = $request->input('insuranceFile');
            }
            if ($request->has('insuranceFileName')) {
                $photographerData['insuranceFileName'] = $request->input('insuranceFileName');
            }
            if ($request->has('specialties')) {
                $specialties = $request->input('specialties');
                if (is_string($specialties)) {
                    $decoded = json_decode($specialties, true);
                    $photographerData['specialties'] = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
                } else {
                    $photographerData['specialties'] = $specialties ?? [];
                }
            }
            
            if (!empty($photographerData)) {
                $validated['metadata'] = array_merge($validated['metadata'] ?? [], $photographerData);
            }
        }

        if ($validated['role'] === 'editor' && $request->has('editing_capabilities')) {
            $editingCapabilities = $request->input('editing_capabilities');
            if (is_string($editingCapabilities)) {
                $decoded = json_decode($editingCapabilities, true);
                $editingCapabilities = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
            }

            $validated['metadata'] = array_merge($validated['metadata'] ?? [], [
                'editing_capabilities' => collect(is_array($editingCapabilities) ? $editingCapabilities : [])
                    ->map(fn ($capability) => strtolower(trim((string) $capability)))
                    ->filter(fn ($capability) => in_array($capability, ['photo', 'video'], true))
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }

        if (in_array($validated['role'] ?? null, ['client', 'photographer'], true)) {
            $validated['metadata'] = array_merge($validated['metadata'] ?? [], array_filter([
                'first_use_legal_agreement_required' => true,
                'first_use_legal_agreement_source' => 'admin_account_created',
                'first_use_legal_agreement_created_at' => now()->toISOString(),
                'first_use_legal_agreement_created_by' => $admin->id,
            ], fn ($value) => $value !== null));
        }

        $validated['metadata'] = app(DashboardOnboardingService::class)->applyEligibility(
            $validated['metadata'] ?? [],
            $validated['role'] ?? '',
            'admin_account_created'
        );

        $user = User::create($validated);
        $pendingEquipmentCount = $user->role === 'photographer'
            ? $this->createPhotographerEquipmentFromRequest($request, $user, $admin, $equipmentPayload, $existingEquipmentIds)
            : 0;
        $this->logUserActivity(
            $user,
            'account_created',
            'Account created',
            sprintf(
                'Created as %s by %s.',
                $this->formatRoleLabel($user->role),
                $admin->name
            ),
            $admin,
            [
                'role' => $user->role,
                'email' => $user->email,
            ]
        );
        if ($emailHealthMutation['warning_override']) {
            $this->logUserActivity(
                $user,
                'email_warning_override',
                'Email warning overridden',
                'The creator confirmed saving an email that matched a likely typo pattern.',
                $admin,
                [
                    'email' => $user->email,
                    'suggested_correction' => $emailHealthMutation['analysis']['suggested_correction'] ?? null,
                    'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                ]
            );
        }
        if ($this->serviceGroupsFeatureAvailable() && $user->role === 'client') {
            if (!$serviceGroupIdsProvided && empty($serviceGroupIds)) {
                $serviceGroupIds = $this->getDefaultServiceGroupIds();
            }

            $user->serviceGroups()->sync($serviceGroupIds);
        }

        if (strtolower((string) $user->email) === self::PRIMARY_SUPERADMIN_EMAIL && $user->role === 'superadmin') {
            $this->demoteOtherSuperAdmins($user);
        }

        $notificationDelivery = app(AccountCreatedNotificationService::class)->dispatch($user, [
            'actor' => $admin,
            'issued_context' => 'admin_account_created',
            'include_password_creation_link' => true,
            'pending_equipment_count' => $pendingEquipmentCount,
            'send_equipment_email' => true,
        ]);

        if ($notificationDelivery['email']['verification']['sent'] ?? false) {
            $this->logUserActivity(
                $user,
                'email_verification_requested',
                'Email verification sent',
                'A verification email was sent when the account was created.',
                $admin,
                [
                    'email' => $user->email,
                    'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                    'issued_context' => 'admin_account_created',
                ]
            );
        }

        if ($pendingEquipmentCount > 0 && (
            ($notificationDelivery['email']['equipment']['sent'] ?? false)
            || (($notificationDelivery['links']['equipment'] ?? null) && ($notificationDelivery['email']['account_created']['sent'] ?? false))
        )) {
            PhotographerEquipment::query()
                ->where('photographer_id', $user->id)
                ->whereIn('status', [PhotographerEquipment::STATUS_PENDING, PhotographerEquipment::STATUS_REJECTED])
                ->whereNull('verification_requested_at')
                ->update(['verification_requested_at' => now()]);
        }
        unset($notificationDelivery['links']);

        $deliveryFailed = collect([
            $notificationDelivery['email']['account_created'],
            $notificationDelivery['email']['verification'],
            $notificationDelivery['email']['equipment'],
            $notificationDelivery['sms'],
        ])->contains(fn (array $channel) => ($channel['attempted'] ?? false) && !($channel['sent'] ?? false));

        return response()->json([
            'message' => $deliveryFailed
                ? 'User created, but one or more notifications failed. Review notification_delivery for details.'
                : 'User created and all attempted notifications were sent successfully.',
            'user' => $this->presentUserForViewer($user, $admin),
            'notification_delivery' => $notificationDelivery,
        ], 201);
    }

    public function getClients(Request $request)
    {
        $clientsQuery = User::query()->where('role', 'client');
        if ($this->serviceGroupsFeatureAvailable()) {
            $clientsQuery->with('serviceGroups');
        }

        $viewer = $request->user();

        $clients = $clientsQuery->get()->map(function ($client) {
            $clientData = $client->toArray();
            $clientData['email_health'] = $client->email_health;
            $rep = null;

            // First, check if client has rep stored in metadata
            $metadata = $client->metadata ?? [];
            if (is_array($metadata) && !empty($metadata)) {
                // Check various possible field names for rep ID
                $repId = $metadata['accountRepId']
                    ?? $metadata['account_rep_id']
                    ?? $metadata['repId']
                    ?? $metadata['rep_id']
                    ?? null;

                if ($repId) {
                    // Convert to integer if it's a string
                    $repId = is_numeric($repId) ? (int)$repId : $repId;
                    $rep = User::find($repId);

                    // Log for debugging
                    \Log::info('Client rep found in metadata', [
                        'client_id' => $client->id,
                        'client_name' => $client->name,
                        'rep_id' => $repId,
                        'rep_found' => $rep ? true : false,
                        'metadata_keys' => array_keys($metadata),
                    ]);
                }
            }

            // If no rep from metadata, check the most recent shoot for this client
            if (!$rep) {
                $mostRecentShoot = \App\Models\Shoot::where('client_id', $client->id)
                    ->whereNotNull('rep_id')
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($mostRecentShoot && $mostRecentShoot->rep_id) {
                    $rep = User::find($mostRecentShoot->rep_id);
                }
            }

            // Add rep information if found
            if ($rep) {
                $clientData['rep'] = [
                    'id' => $rep->id,
                    'name' => $rep->name,
                    'email' => $rep->email,
                ];
            }

            $clientData['service_group_ids'] = $client->getAssignedServiceGroupIds();
            $clientData['shoot_cc_emails'] = $this->sanitizeShootCcEmails($clientData['shoot_cc_emails'] ?? []);
            $clientData['client_discount_type'] = $clientData['client_discount_type'] ?? null;
            $clientData['client_discount_value'] = $clientData['client_discount_value'] !== null
                ? (float) $clientData['client_discount_value']
                : null;
            $clientData['shootCcEmails'] = $clientData['shoot_cc_emails'];
            $clientData['clientDiscountType'] = $clientData['client_discount_type'];
            $clientData['clientDiscountValue'] = $clientData['client_discount_value'];

            return $clientData;
        });

        return response()->json([
            'status' => 'success',
            'data' => $clients
        ]);
    }

    public function getPhotographers(Request $request)
    {
        $photographersQuery = User::where(function ($q) {
            $q->where('role', 'photographer')
                ->orWhereJsonContains('secondary_roles', 'photographer');
        });

        $viewer = $request->user();
        if ($this->isSalesRepUser($viewer)) {
            $scope = $this->getSalesRepShootScope($viewer);
            $photographers = $photographersQuery
                ->get()
                ->filter(fn (User $photographer) => $this->salesRepCanAccessAccount($viewer, $photographer, $scope))
                ->values();
        } else {
            $photographers = $photographersQuery->get();
        }

        $policy = app(PhotographerAddressPolicy::class);
        $presented = $photographers->map(function (User $photographer) use ($viewer, $policy) {
            return $policy->presentSubjectForViewer($photographer->toArray(), $viewer, $photographer);
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $presented,
        ]);
    }

    // Lightweight public list (id + name + email + avatar) for UI dropdowns
    public function simplePhotographers()
    {
        $photographers = \Illuminate\Support\Facades\Cache::remember('photographers_list_v3', 300, function () {
            return User::where(function ($q) {
                    $q->where('role', 'photographer')
                      ->orWhereJsonContains('secondary_roles', 'photographer');
                })
                ->select('id', 'name', 'email', 'avatar')
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'data' => $photographers
        ]);
    }

    /**
     * Admin-only: update a user's primary role
     */
    public function update(Request $request, $id)
    {
        $admin = $request->user();
        if (!$this->userHasAnyRole($admin, ['admin', 'superadmin', 'editing_manager', 'salesRep'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);
        if ($this->isSalesRepUser($admin) && !$this->salesRepCanAccessAccount($admin, $user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $rules = [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'email_warning_override' => 'nullable|boolean',
            'timezone' => 'nullable|string|max:64',
            'username' => 'nullable|string|unique:users,username,' . $id,
            'phone_number' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip' => 'nullable|string|max:20',
            'license_number' => 'nullable|string|max:100',
            'company_notes' => 'nullable|string',
            'shoot_cc_emails' => 'nullable|array',
            'shoot_cc_emails.*' => 'email',
            'clear_shoot_cc_emails' => 'nullable|boolean',
            'client_discount_type' => 'nullable|in:fixed,percent',
            'client_discount_value' => 'nullable|numeric|min:0',
            'role' => 'sometimes|in:superadmin,admin,editing_manager,client,photographer,editor,salesRep',
            'account_status' => 'sometimes|in:active,inactive,suspended',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string',
            'metadata' => 'nullable',
            'preferences' => 'sometimes|array',
            'preferences.notificationEmail' => 'nullable|boolean',
            'created_by_name' => 'nullable|string|max:255',
            'created_by_id' => 'nullable|integer|exists:users,id',
            'pilotLicenseFile' => 'nullable|string|url',
            'pilotLicenseFileName' => 'nullable|string|max:255',
            'insuranceNumber' => 'nullable|string|max:255',
            'insuranceFile' => 'nullable|string|url',
            'insuranceFileName' => 'nullable|string|max:255',
            'specialties' => 'nullable|string',
            'editing_capabilities' => 'nullable|string',
            // Photographer default HDR bracket size. Seeds new bracket-capable
            // shoot-service assignments only; existing assignments keep their own value.
            'default_bracket_mode' => 'nullable|integer|in:3,5',
        ];

        if ($this->serviceGroupsFeatureAvailable()) {
            $rules['service_group_ids'] = 'nullable|array';
            $rules['service_group_ids.*'] = 'integer|exists:service_groups,id';
        }

        $validated = $request->validate($rules);

        $addressPolicy = app(PhotographerAddressPolicy::class);
        if ($addressPolicy->isPhotographer($user) && !$addressPolicy->canApproveAddressChanges($admin)) {
            unset($validated['address'], $validated['city'], $validated['state'], $validated['zip']);
        }

        if ($this->isSalesRepUser($admin)) {
            if (!in_array($user->role, $this->salesRepCreatableRoles(), true)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            if (array_key_exists('role', $validated) && ($validated['role'] ?? null) !== $user->role) {
                return response()->json([
                    'message' => 'Sales reps cannot change account roles.',
                ], 403);
            }
        }

        $requestedRole = $validated['role'] ?? $user->role;
        $requestedEmail = strtolower((string) ($validated['email'] ?? $user->email));
        if ($response = $this->guardPrimarySuperAdmin($user, $requestedRole)) {
            return $response;
        }
        if ($requestedEmail === self::PRIMARY_SUPERADMIN_EMAIL && $requestedRole !== 'superadmin') {
            return response()->json([
                'message' => 'The primary superadmin must remain aj@reprophotos.com.',
            ], 422);
        }
        if ($requestedRole === 'superadmin' && $requestedEmail !== self::PRIMARY_SUPERADMIN_EMAIL) {
            return response()->json([
                'message' => 'Only aj@reprophotos.com can be a superadmin.',
            ], 422);
        }

        $previousEmail = strtolower((string) $user->email);
        $previousEmailStatus = strtolower((string) ($user->email_status ?? ''));
        $emailHealthMutation = $this->resolveEmailHealthMutation($validated, $user);
        if ($emailHealthMutation['response'] !== null) {
            return $emailHealthMutation['response'];
        }

        unset($validated['email_warning_override']);
        $validated = array_merge($validated, $emailHealthMutation['attributes']);

        $serviceGroupIdsProvided = array_key_exists('service_group_ids', $validated);
        $serviceGroupIds = collect($validated['service_group_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        unset($validated['service_group_ids']);

        if (array_key_exists('phone_number', $validated)) {
            $validated['phonenumber'] = $validated['phone_number'];
            unset($validated['phone_number']);
        }

        if (($validated['clear_shoot_cc_emails'] ?? false) && !array_key_exists('shoot_cc_emails', $validated)) {
            $validated['shoot_cc_emails'] = [];
        }
        unset($validated['clear_shoot_cc_emails']);

        if (array_key_exists('shoot_cc_emails', $validated)) {
            $validated['shoot_cc_emails'] = $this->sanitizeShootCcEmails($validated['shoot_cc_emails'] ?? []);
        }

        if (
            array_key_exists('client_discount_type', $validated)
            || array_key_exists('client_discount_value', $validated)
            || array_key_exists('role', $validated)
        ) {
            [$validated['client_discount_type'], $validated['client_discount_value']] = $this->normalizeClientDiscount(
                $validated['role'] ?? $user->role,
                $validated['client_discount_type'] ?? $user->client_discount_type,
                $validated['client_discount_value'] ?? $user->client_discount_value
            );
        }

        // Handle metadata
        if ($request->has('metadata')) {
            $metadata = $request->input('metadata');
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }
            if (is_array($metadata)) {
                $validated['metadata'] = $this->filterMetadataForWriter($metadata, $admin);
            }
        }

        if (array_key_exists('preferences', $validated) && is_array($validated['preferences'])) {
            $metadata = is_array($validated['metadata'] ?? null)
                ? $validated['metadata']
                : (is_array($user->metadata) ? $user->metadata : []);
            $existingPreferences = is_array($metadata['preferences'] ?? null) ? $metadata['preferences'] : [];
            $metadata['preferences'] = array_replace($existingPreferences, $validated['preferences']);
            $validated['metadata'] = $metadata;
            unset($validated['preferences']);
        }

        if ($this->isSalesRepUser($admin)) {
            unset($validated['created_by_id'], $validated['created_by_name']);
            unset($validated['account_status']);
        }

        // Add photographer-specific fields to metadata
        if ($user->role === 'photographer' || ($request->has('role') && $request->input('role') === 'photographer')) {
            $photographerData = array_merge(
                is_array($user->metadata) ? $user->metadata : [],
                is_array($validated['metadata'] ?? null) ? $validated['metadata'] : []
            );
            
            if ($request->has('pilotLicenseFile')) {
                $photographerData['pilotLicenseFile'] = $request->input('pilotLicenseFile');
            }
            if ($request->has('pilotLicenseFileName')) {
                $photographerData['pilotLicenseFileName'] = $request->input('pilotLicenseFileName');
            }
            if ($request->has('insuranceNumber')) {
                $photographerData['insuranceNumber'] = $request->input('insuranceNumber');
            }
            if ($request->has('insuranceFile')) {
                $photographerData['insuranceFile'] = $request->input('insuranceFile');
            }
            if ($request->has('insuranceFileName')) {
                $photographerData['insuranceFileName'] = $request->input('insuranceFileName');
            }
            if ($request->has('specialties')) {
                $specialties = $request->input('specialties');
                if (is_string($specialties)) {
                    $decoded = json_decode($specialties, true);
                    $photographerData['specialties'] = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
                } else {
                    $photographerData['specialties'] = $specialties ?? [];
                }
            }
            
            $validated['metadata'] = $photographerData;
        }

        if ($user->role === 'editor' || ($request->has('role') && $request->input('role') === 'editor')) {
            $editorData = array_merge(
                is_array($user->metadata) ? $user->metadata : [],
                is_array($validated['metadata'] ?? null) ? $validated['metadata'] : []
            );

            if ($request->has('editing_capabilities')) {
                $editingCapabilities = $request->input('editing_capabilities');
                if (is_string($editingCapabilities)) {
                    $decoded = json_decode($editingCapabilities, true);
                    $editingCapabilities = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
                }

                $editorData['editing_capabilities'] = collect(is_array($editingCapabilities) ? $editingCapabilities : [])
                    ->map(fn ($capability) => strtolower(trim((string) $capability)))
                    ->filter(fn ($capability) => in_array($capability, ['photo', 'video'], true))
                    ->unique()
                    ->values()
                    ->all();
            }

            $validated['metadata'] = $editorData;
        }

        $existingServiceGroupIds = $this->serviceGroupsFeatureAvailable()
            ? $user->getAssignedServiceGroupIds()
            : [];
        $oldRoleForNotification = (string) $user->role;
        $oldSecondaryRolesForNotification = $user->secondary_roles ?? [];
        $previousPhoneForNotification = trim((string) ($user->phonenumber ?: $user->phone));

        $user->fill($validated);
        $changedFields = collect(array_keys($user->getDirty()))
            ->reject(fn ($field) => in_array($field, ['updated_at', 'password', 'remember_token'], true))
            ->values()
            ->all();

        $user->save();
        if ($this->serviceGroupsFeatureAvailable() && $user->role === 'client') {
            if ($serviceGroupIdsProvided) {
                $user->serviceGroups()->sync($serviceGroupIds);
            }
        } elseif ($this->serviceGroupsFeatureAvailable() && ($serviceGroupIdsProvided || $user->serviceGroups()->exists())) {
            $user->serviceGroups()->detach();
        }

        $updatedServiceGroupIds = $this->serviceGroupsFeatureAvailable()
            ? $user->getAssignedServiceGroupIds()
            : [];
        $serviceGroupsChanged = $existingServiceGroupIds !== $updatedServiceGroupIds;

        if ($serviceGroupsChanged) {
            $changedFields[] = 'service_group_ids';
        }

        $changedFields = array_values(array_unique($changedFields));
        $roleChanged = in_array('role', $changedFields, true);

        if (strtolower((string) $user->email) === self::PRIMARY_SUPERADMIN_EMAIL && $user->role === 'superadmin') {
            $this->demoteOtherSuperAdmins($user);
        }

        if (!empty($changedFields)) {
            $this->logUserActivity(
                $user,
                'account_updated',
                'Account profile updated',
                $this->summarizeChangedAttributes($changedFields),
                $admin,
                [
                    'changed_fields' => $changedFields,
                ]
            );
        }

        if ($roleChanged) {
            try {
                app(MailService::class)->sendRoleChangedEmail(
                    $user,
                    $oldRoleForNotification,
                    (string) $user->role,
                    $oldSecondaryRolesForNotification,
                    $user->secondary_roles ?? []
                );
            } catch (\Throwable $exception) {
                \Log::warning('Failed to send role change email after account update', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $phoneNotificationDelivery = app(PhoneNumberChangedNotificationService::class)->dispatch(
            $user,
            $previousPhoneForNotification,
            trim((string) ($user->phonenumber ?: $user->phone)),
            $admin
        );

        if ($emailHealthMutation['warning_override']) {
            $this->logUserActivity(
                $user,
                'email_warning_override',
                'Email warning overridden',
                'The account editor confirmed keeping a likely typo email address.',
                $admin,
                [
                    'email' => $user->email,
                    'suggested_correction' => $emailHealthMutation['analysis']['suggested_correction'] ?? null,
                    'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                ]
            );
        }

        if ($emailHealthMutation['email_changed'] && $this->shouldRequireEmailVerificationForRole($user->role)) {
            $verificationSent = false;
            try {
                $mailService = app(MailService::class);
                if ($mailService->sendClientEmailVerificationEmail($user, [
                    'issued_context' => 'admin_email_change',
                    'issued_by' => $admin->id,
                ])) {
                    $this->emailHealthService->markVerificationSent($user);
                    $verificationSent = true;
                }
            } catch (\Throwable $exception) {
                \Log::warning('Failed to send account email verification email after update', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $exception->getMessage(),
                ]);
            }

            if ($verificationSent) {
                $this->logUserActivity(
                    $user,
                    'email_verification_requested',
                    'Email verification sent',
                    'A new verification email was sent after the account email changed.',
                    $admin,
                    [
                        'email' => $user->email,
                        'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                    ]
                );
            }

            if ($previousEmailStatus === EmailHealthService::STATUS_BOUNCED && $previousEmail !== strtolower((string) $user->email)) {
                $this->logUserActivity(
                    $user,
                    'email_corrected_after_bounce',
                    'Bounced email corrected',
                    sprintf('Client email changed from %s to %s after a bounce warning.', $previousEmail, strtolower((string) $user->email)),
                    $admin,
                    [
                        'previous_email' => $previousEmail,
                        'updated_email' => strtolower((string) $user->email),
                        'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                    ]
                );
            }
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $this->presentUserForViewer($user, $admin),
            'phone_notification_delivery' => $phoneNotificationDelivery,
        ]);
    }

    public function updateRole(Request $request, $id)
    {
        $admin = $request->user();
        if (!$admin || !in_array($admin->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'role' => 'required|in:superadmin,admin,editing_manager,client,photographer,editor,salesRep',
            'secondary_roles' => 'nullable|array',
            'secondary_roles.*' => 'in:superadmin,admin,editing_manager,client,photographer,editor,salesRep',
        ]);

        $user = User::findOrFail($id);
        if ($response = $this->guardPrimarySuperAdmin($user, $validated['role'] ?? null)) {
            return $response;
        }
        $oldRole = $user->role;
        $oldSecondaryRoles = $user->secondary_roles ?? [];
        $user->role = $validated['role'];
        $user->secondary_roles = $validated['secondary_roles'] ?? [];
        $changed = $user->isDirty('role') || $user->isDirty('secondary_roles');
        $user->save();

        if (strtolower((string) $user->email) === self::PRIMARY_SUPERADMIN_EMAIL && $user->role === 'superadmin') {
            $this->demoteOtherSuperAdmins($user);
        }

        if ($changed) {
            $secondaryRoleSummary = collect($user->secondary_roles ?? [])
                ->map(fn ($role) => $this->formatRoleLabel((string) $role))
                ->implode(', ');

            $description = sprintf(
                'Primary role changed from %s to %s%s.',
                $this->formatRoleLabel((string) $oldRole),
                $this->formatRoleLabel((string) $user->role),
                $secondaryRoleSummary !== '' ? " with secondary roles: {$secondaryRoleSummary}" : ''
            );

            $this->logUserActivity(
                $user,
                'role_changed',
                'Role permissions updated',
                $description,
                $admin,
                [
                    'old_role' => $oldRole,
                    'new_role' => $user->role,
                    'old_secondary_roles' => $oldSecondaryRoles,
                    'new_secondary_roles' => $user->secondary_roles ?? [],
                ]
            );

            // Send role change email notification
            try {
                $mailService = app(MailService::class);
                $mailService->sendRoleChangedEmail(
                    $user,
                    $oldRole,
                    $user->role,
                    $oldSecondaryRoles,
                    $user->secondary_roles ?? []
                );
            } catch (\Exception $e) {
                \Log::warning('Failed to send role change email', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => $changed ? 'Roles updated successfully.' : 'Roles unchanged.',
            'changed' => $changed,
            'user' => $user,
            'old_role' => $oldRole,
            'new_role' => $user->role,
            'old_secondary_roles' => $oldSecondaryRoles,
            'new_secondary_roles' => $user->secondary_roles,
        ]);
    }

    /**
     * Admin-only: reset a user's password
     */
    public function resetPassword(Request $request, $id)
    {
        $admin = $request->user();
        if (!$admin || !in_array($admin->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $user = User::findOrFail($id);
        if ($user->role === 'superadmin' && $admin->role !== 'superadmin') {
            return response()->json(['message' => 'Only a superadmin can reset a superadmin password.'], 403);
        }

        DB::transaction(function () use ($user, $validated): void {
            $user->password = $validated['password'];
            $user->password_changed_at = now();
            $user->password_reset_required = false;
            $user->save();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')
                ->where('email', strtolower((string) $user->email))
                ->delete();
        });

        $context = ['account_id' => $user->id];
        $role = strtolower((string) $user->role);
        if ($role === 'client') {
            $context['client'] = $user;
        } elseif ($role === 'photographer') {
            $context['photographer'] = $user;
        } elseif ($role === 'salesrep') {
            $context['rep'] = $user;
        } else {
            $context['client'] = $user;
        }
        app(AutomationService::class)->handleEvent('PASSWORD_RESET', $context);

        $this->logUserActivity(
            $user,
            'password_reset',
            'Password reset by admin',
            sprintf('Password was reset by %s. All dashboard sessions were signed out.', $admin->name),
            $admin
        );

        return response()->json([
            'message' => 'Password updated successfully.',
            'user_id' => $user->id,
        ]);
    }

    /**
     * Admin-only: send password reset link to user
     */
    public function sendResetLink(Request $request, $id)
    {
        $admin = $request->user();
        if (!$admin || !in_array($admin->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);
        if ($user->role === 'superadmin' && $admin->role !== 'superadmin') {
            return response()->json(['message' => 'Only a superadmin can send a reset link to a superadmin.'], 403);
        }
        
        // Generate a password reset token
        $token = Str::random(64);
        
        // Store the token in password_reset_tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );
        
        // Generate the reset link and send email
        $mailService = app(MailService::class);
        $resetLink = $mailService->generatePasswordResetLink($user, $token);
        $sent = $mailService->sendPasswordResetEmail($user, $resetLink);
        
        if (!$sent) {
            return response()->json([
                'message' => 'Failed to send password reset email. Please try again.',
            ], 500);
        }

        $this->logUserActivity(
            $user,
            'password_reset_link_sent',
            'Password reset link sent',
            sprintf('Password reset link emailed by %s.', $admin->name),
            $admin,
            [
                'email' => $user->email,
            ]
        );

        return response()->json([
            'message' => 'Password reset link sent successfully.',
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    public function resendVerificationEmail(Request $request, $id)
    {
        $admin = $request->user();
        if (!$this->userHasAnyRole($admin, ['admin', 'superadmin', 'editing_manager', 'salesRep'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);

        if ($this->isSalesRepUser($admin) && !$this->salesRepCanAccessAccount($admin, $user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$this->shouldRequireEmailVerificationForRole($user->role)) {
            return response()->json([
                'sent' => false,
                'message' => 'Verification emails are not required for this account role.',
            ], 422);
        }

        if (blank($user->email)) {
            return response()->json([
                'sent' => false,
                'message' => 'This account does not have an email address on file.',
            ], 422);
        }

        if (strtolower((string) $user->email_status) === EmailHealthService::STATUS_VERIFIED) {
            return response()->json([
                'sent' => false,
                'message' => 'This email address is already verified.',
            ], 422);
        }

        $mailService = app(MailService::class);

        try {
            $sent = $mailService->sendClientEmailVerificationEmail($user, [
                'issued_context' => 'admin_profile_resend',
                'issued_by' => $admin->id,
                'throw_on_failure' => true,
            ]);
        } catch (\Throwable $sendException) {
            return response()->json([
                'sent' => false,
                'message' => 'Failed to send verification email: ' . $sendException->getMessage(),
            ], 422);
        }

        if (!$sent) {
            return response()->json([
                'sent' => false,
                'message' => 'Failed to send verification email. Please try again.',
            ], 422);
        }

        $this->emailHealthService->markVerificationSent($user);

        $this->logUserActivity(
            $user,
            'email_verification_requested',
            'Email verification resent',
            sprintf('A verification email was resent by %s.', $admin->name),
            $admin,
            [
                'email' => $user->email,
                'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
            ]
        );

        $user->refresh();

        return response()->json([
            'sent' => true,
            'email' => $user->email,
            'message' => 'Verification email sent successfully.',
            'user' => $this->presentUserForViewer($user, $admin),
        ]);
    }

    public function approvePhotographerAddressChange(Request $request, $id)
    {
        return $this->reviewPhotographerAddressChange($request, $id, 'approve');
    }

    public function rejectPhotographerAddressChange(Request $request, $id)
    {
        $request->validate([
            'review_note' => 'nullable|string|max:1000',
        ]);

        return $this->reviewPhotographerAddressChange($request, $id, 'reject', $request->input('review_note'));
    }

    protected function reviewPhotographerAddressChange(Request $request, $id, string $decision, ?string $note = null)
    {
        $admin = $request->user();
        $policy = app(PhotographerAddressPolicy::class);
        if (!$policy->canApproveAddressChanges($admin)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);
        $pending = $policy->pendingChangeFor($user);
        if (!$pending) {
            return response()->json([
                'message' => 'This photographer does not have a pending address change.',
            ], 422);
        }

        $updated = $decision === 'approve'
            ? $policy->approve($pending, $admin)
            : $policy->reject($pending, $admin, $note);

        $this->logUserActivity(
            $user,
            $decision === 'approve' ? 'photographer_address_approved' : 'photographer_address_rejected',
            $decision === 'approve' ? 'Photographer address approved' : 'Photographer address rejected',
            $decision === 'approve'
                ? sprintf('Address change approved by %s.', $admin->name)
                : sprintf('Address change rejected by %s.', $admin->name),
            $admin,
            ['request_id' => $updated->id]
        );

        return response()->json([
            'message' => $decision === 'approve'
                ? 'Photographer address change approved.'
                : 'Photographer address change rejected.',
            'user' => $this->presentUserForViewer($user->fresh(), $admin),
        ]);
    }

    protected function salesRepCreatableRoles(): array
    {
        return ['client'];
    }

    protected function getSalesRepShootScope(User $salesRep): array
    {
        $salesRepShoots = \App\Models\Shoot::query()
            ->where('rep_id', $salesRep->id)
            ->get(['client_id', 'photographer_id']);

        return [
            'client_ids' => $salesRepShoots
                ->pluck('client_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all(),
            'photographer_ids' => $salesRepShoots
                ->pluck('photographer_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    protected function salesRepOwnsClient(User $salesRep, User $client, ?array $scope = null): bool
    {
        return $client->role === 'client';
    }

    protected function salesRepOwnsPhotographer(User $salesRep, User $photographer, ?array $scope = null): bool
    {
        $secondaryRoles = is_array($photographer->secondary_roles) ? $photographer->secondary_roles : [];
        $isPhotographer = $photographer->role === 'photographer' || in_array('photographer', $secondaryRoles, true);

        if (!$isPhotographer) {
            return false;
        }

        return true;
    }

    protected function salesRepCanAccessAccount(User $salesRep, User $account, ?array $scope = null): bool
    {
        return $this->salesRepOwnsClient($salesRep, $account, $scope)
            || $this->salesRepOwnsPhotographer($salesRep, $account, $scope);
    }

    protected function getSalesRepVisibleAccounts(User $salesRep)
    {
        $scope = $this->getSalesRepShootScope($salesRep);

        $clientQuery = User::query()->where('role', 'client');
        if ($this->serviceGroupsFeatureAvailable()) {
            $clientQuery->with('serviceGroups');
        }

        $photographerQuery = User::query()->where(function ($query) {
            $query->where('role', 'photographer')
                ->orWhereJsonContains('secondary_roles', 'photographer');
        });
        if ($this->serviceGroupsFeatureAvailable()) {
            $photographerQuery->with('serviceGroups');
        }

        $clientUsers = $clientQuery
            ->get();

        $photographerUsers = $photographerQuery
            ->get()
            ->filter(fn (User $photographer) => $this->salesRepOwnsPhotographer($salesRep, $photographer, $scope))
            ->values();

        return $clientUsers
            ->concat($photographerUsers)
            ->unique(fn (User $record) => (string) $record->id)
            ->values();
    }

    protected function sanitizeShootCcEmails(array $emails): array
    {
        return collect($emails)
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *   attributes: array<string, mixed>,
     *   analysis: array<string, mixed>|null,
     *   warning_override: bool,
     *   email_changed: bool,
     *   response: \Illuminate\Http\JsonResponse|null
     * }
     */
    protected function resolveEmailHealthMutation(array $validated, ?User $existingUser = null): array
    {
        $targetRole = $validated['role'] ?? $existingUser?->role;
        $email = strtolower(trim((string) ($validated['email'] ?? $existingUser?->email ?? '')));
        $warningOverride = (bool) ($validated['email_warning_override'] ?? false);
        $emailChanged = $existingUser
            ? array_key_exists('email', $validated) && $email !== strtolower(trim((string) $existingUser->email))
            : true;

        if (!$this->shouldRequireEmailVerificationForRole($targetRole)) {
            return [
                'attributes' => $existingUser ? $this->clearEmailHealthAttributes() : [],
                'analysis' => null,
                'warning_override' => false,
                'email_changed' => $emailChanged,
                'response' => null,
            ];
        }

        if ($email === '' || (!$emailChanged && $existingUser)) {
            return [
                'attributes' => [],
                'analysis' => null,
                'warning_override' => false,
                'email_changed' => $emailChanged,
                'response' => null,
            ];
        }

        $analysis = $this->emailHealthService->analyzeForSave($email);
        if (!$analysis['valid'] || ($analysis['requires_confirmation'] && !$warningOverride)) {
            return [
                'attributes' => [],
                'analysis' => $analysis,
                'warning_override' => $warningOverride,
                'email_changed' => $emailChanged,
                'response' => $this->buildEmailHealthValidationResponse($email, $analysis),
            ];
        }

        return [
            'attributes' => $this->emailHealthService->buildAttributesForSave($email, $analysis),
            'analysis' => $analysis,
            'warning_override' => $warningOverride,
            'email_changed' => $emailChanged,
            'response' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    protected function buildEmailHealthValidationResponse(string $email, array $analysis)
    {
        return response()->json([
            'message' => $analysis['error_message'] ?? $analysis['warning_message'] ?? 'Email validation failed.',
            'errors' => [
                'email' => [
                    $analysis['error_message'] ?? $analysis['warning_message'] ?? 'Email validation failed.',
                ],
            ],
            'email_health' => [
                'status' => $analysis['status'] ?? null,
                'warning_code' => $analysis['warning_code'] ?? null,
                'warning_message' => $analysis['warning_message'] ?? null,
                'suggested_correction' => $analysis['suggested_correction'] ?? null,
                'requires_confirmation' => (bool) ($analysis['requires_confirmation'] ?? false),
                'entered_email' => $email,
            ],
        ], 422);
    }

    /**
     * @return array<string, null>
     */
    protected function clearEmailHealthAttributes(): array
    {
        return [
            'email_status' => null,
            'verification_sent_at' => null,
            'email_verified_at' => null,
            'email_last_delivery_attempt_at' => null,
            'email_last_bounced_at' => null,
            'email_bounce_reason' => null,
            'email_warning_code' => null,
            'email_warning_message' => null,
            'email_suggested_correction' => null,
        ];
    }

    protected function shouldRequireEmailVerificationForRole(?string $role): bool
    {
        return !in_array($role, ['admin', 'superadmin'], true);
    }

    protected function normalizeAccountNotificationPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return str_starts_with(trim($phone), '+') ? trim($phone) : '+' . $digits;
    }

    protected function normalizeClientDiscount(?string $role, mixed $discountType, mixed $discountValue): array
    {
        if ($role !== 'client') {
            return [null, null];
        }

        $normalizedType = is_string($discountType) ? strtolower(trim($discountType)) : null;
        if (!in_array($normalizedType, ['fixed', 'percent'], true)) {
            return [null, null];
        }

        if ($discountValue === null || $discountValue === '') {
            return [null, null];
        }

        $numericValue = round(max((float) $discountValue, 0), 2);
        if ($normalizedType === 'percent') {
            $numericValue = min($numericValue, 100);
        }

        if ($numericValue <= 0) {
            return [null, null];
        }

        return [$normalizedType, $numericValue];
    }

    protected function presentUserForViewer(User $user, User $viewer): array
    {
        $payload = $user->attributesToArray();
        $payload['service_groups'] = $this->serializeServiceGroups($user);
        $payload['service_group_ids'] = array_map(
            fn ($group) => (string) $group['id'],
            $payload['service_groups']
        );
        
        // Map database fields to frontend field names
        if (isset($payload['phonenumber'])) {
            $payload['phone'] = $payload['phonenumber'];
            $payload['phone_number'] = $payload['phonenumber'];
        }
        if (isset($payload['company_name'])) {
            $payload['company'] = $payload['company_name'];
        }
        if (isset($payload['zip'])) {
            $payload['zipcode'] = $payload['zip'];
        }
        if (isset($payload['license_number'])) {
            $payload['licenseNumber'] = $payload['license_number'];
        }
        if (isset($payload['company_notes'])) {
            $payload['companyNotes'] = $payload['company_notes'];
        }
        $payload['shoot_cc_emails'] = $this->sanitizeShootCcEmails($payload['shoot_cc_emails'] ?? []);
        $payload['shootCcEmails'] = $payload['shoot_cc_emails'];
        $payload['email_health'] = $user->email_health;
        $payload = app(PhotographerAddressPolicy::class)->presentSubjectForViewer($payload, $viewer, $user);
        $payload['editingCapabilities'] = $user->getEditingCapabilities();
        $payload['editing_capabilities'] = $payload['editingCapabilities'];
        $payload['client_discount_type'] = $payload['client_discount_type'] ?? null;
        $payload['client_discount_value'] = isset($payload['client_discount_value']) && $payload['client_discount_value'] !== null
            ? (float) $payload['client_discount_value']
            : null;
        $payload['clientDiscountType'] = $payload['client_discount_type'];
        $payload['clientDiscountValue'] = $payload['client_discount_value'];
        if (isset($payload['zip'])) {
            $payload['zipcode'] = $payload['zip'];
        }
        if (isset($payload['license_number'])) {
            $payload['licenseNumber'] = $payload['license_number'];
        }
        if (isset($payload['company_notes'])) {
            $payload['companyNotes'] = $payload['company_notes'];
        }
        
        // Handle account linking - merge shared data with error handling
        try {
            $linkedAccounts = $this->getLinkedAccounts($user);
            $sharedData = $this->getSharedAccountData($user, $linkedAccounts);
            
            // Add shared information to payload
            $payload['linkedAccounts'] = $linkedAccounts;
            $payload['sharedData'] = $sharedData;
            $payload['totalShoots'] = $sharedData['totalShoots'] ?? 0;
            $payload['totalSpent'] = $sharedData['totalSpent'] ?? 0;
            $payload['linkedProperties'] = $sharedData['properties'] ?? [];
        } catch (\Exception $e) {
            // Fallback to empty data if account linking fails
            $payload['linkedAccounts'] = [];
            $payload['sharedData'] = [
                'totalShoots' => 0,
                'totalSpent' => 0,
                'properties' => [],
                'paymentHistory' => [],
                'lastActivity' => null,
            ];
            $payload['totalShoots'] = 0;
            $payload['totalSpent'] = 0;
            $payload['linkedProperties'] = [];
        }
        
        if (!$this->viewerIsSuperAdmin($viewer)) {
            if (is_array($payload['metadata'])) {
                Arr::forget($payload['metadata'], 'repDetails.homeAddress');
                Arr::forget($payload['metadata'], 'repDetails.commissionPercentage');
            }
        }

        return $payload;
    }

    protected function filterMetadataForWriter(array $metadata, User $viewer): array
    {
        if ($this->viewerIsSuperAdmin($viewer)) {
            return $metadata;
        }

        Arr::forget($metadata, 'repDetails.homeAddress');
        Arr::forget($metadata, 'repDetails.commissionPercentage');

        return $metadata;
    }

    protected function viewerIsSuperAdmin(?User $viewer): bool
    {
        if (!$viewer) {
            return false;
        }

        return in_array($viewer->role, ['superadmin'], true);
    }

    protected function normalizeRole(?string $role): string
    {
        if ($role === null) {
            return '';
        }

        return strtolower(str_replace(['_', '-'], '', $role));
    }

    protected function userHasAnyRole(?User $user, array $roles): bool
    {
        if (!$user) {
            return false;
        }

        $normalizedUserRole = $this->normalizeRole($user->role);
        $normalizedSecondaryRoles = collect(is_array($user->secondary_roles) ? $user->secondary_roles : [])
            ->map(fn ($role) => $this->normalizeRole($role))
            ->filter()
            ->values()
            ->all();
        $normalizedAllowedRoles = array_map(fn ($role) => $this->normalizeRole($role), $roles);

        return in_array($normalizedUserRole, $normalizedAllowedRoles, true)
            || !empty(array_intersect($normalizedSecondaryRoles, $normalizedAllowedRoles));
    }

    protected function isSalesRepUser(?User $user): bool
    {
        return $this->userHasAnyRole($user, ['salesRep']);
    }

    protected function guardPrimarySuperAdmin(User $user, ?string $requestedRole)
    {
        if ($requestedRole === null) {
            return null;
        }

        $normalizedEmail = strtolower((string) $user->email);
        $isPrimary = $normalizedEmail === self::PRIMARY_SUPERADMIN_EMAIL;

        if ($isPrimary && $requestedRole !== 'superadmin') {
            return response()->json([
                'message' => 'The primary superadmin must remain aj@reprophotos.com.',
            ], 422);
        }

        if (!$isPrimary && $requestedRole === 'superadmin') {
            return response()->json([
                'message' => 'Only aj@reprophotos.com can be a superadmin.',
            ], 422);
        }

        return null;
    }

    protected function demoteOtherSuperAdmins(User $primary): void
    {
        if (strtolower((string) $primary->email) !== self::PRIMARY_SUPERADMIN_EMAIL) {
            return;
        }

        User::where('role', 'superadmin')
            ->where('id', '!=', $primary->id)
            ->update(['role' => 'admin']);
    }

    /**
     * Get all accounts linked to this user
     */
    protected function getLinkedAccounts(User $user): array
    {
        // Get child accounts (linked to this parent)
        $links = \App\Models\AccountLink::forAccount($user->id)
            ->active()
            ->with(['mainAccount', 'linkedAccount'])
            ->get();

        $linkedAccounts = [];
        
        foreach ($links as $link) {
            $linkedUser = $link->main_account_id === $user->id 
                ? $link->linkedAccount 
                : $link->mainAccount;

            $linkedAccounts[] = [
                'id' => $linkedUser->id,
                'name' => $linkedUser->name,
                'email' => $linkedUser->email,
                'role' => $linkedUser->role,
                'account_status' => $linkedUser->account_status ?? 'active',
                'sharedDetails' => $link->getFormattedSharedDetails(),
                'linkedAt' => $link->linked_at->toISOString(),
                'linkId' => $link->id,
            ];
        }

        return $linkedAccounts;
    }

    /**
     * Aggregate shared data from all linked accounts
     */
    protected function getSharedAccountData(User $user, array $linkedAccounts): array
    {
        $sharedData = [
            'totalShoots' => 0,
            'totalSpent' => 0,
            'properties' => [],
            'paymentHistory' => [],
            'lastActivity' => null,
            'communicationHistory' => [
                'emails' => [],
                'sms' => [],
                'calls' => [],
                'notes' => [],
            ],
        ];

        $shootAccountIds = array_merge(
            [$user->id],
            \App\Models\AccountLink::getSharedAccountIdsForDetail((int) $user->id, 'shoots'),
        );
        $invoiceAccountIds = array_merge(
            [$user->id],
            \App\Models\AccountLink::getSharedAccountIdsForDetail((int) $user->id, 'invoices'),
        );

        if (count($shootAccountIds) > 1) {
            $shootQuery = \App\Models\Shoot::whereIn('client_id', $shootAccountIds);
            $sharedData['totalShoots'] = $shootQuery->count();

            // Group shoots by address to create properties list
            $properties = $shootQuery
                ->get()
                ->groupBy(function ($shoot) {
                    // Group by address, city, state combination
                    return strtolower(trim($shoot->address . '|' . $shoot->city . '|' . $shoot->state));
                })
                ->map(function ($shoots) {
                    $first = $shoots->first();

                    return [
                        'id' => null,
                        'address' => $first->address ?? '',
                        'city' => $first->city ?? '',
                        'state' => $first->state ?? '',
                        'shootCount' => $shoots->count(),
                    ];
                })
                ->values()
                ->toArray();

            $sharedData['properties'] = $properties;
            $lastShoot = \App\Models\Shoot::whereIn('client_id', $shootAccountIds)
                ->orderBy('updated_at', 'desc')
                ->first();
            $sharedData['lastActivity'] = $lastShoot?->updated_at?->toISOString();
        }

        if (count($invoiceAccountIds) > 1) {
            $sharedData['totalSpent'] = \App\Models\Shoot::whereIn('client_id', $invoiceAccountIds)->sum('total_quote') ?? 0;

            $sharedData['paymentHistory'] = \App\Models\Payment::whereHas('shoot', function ($query) use ($invoiceAccountIds) {
                    $query->whereIn('client_id', $invoiceAccountIds);
                })
                ->with('shoot')
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get()
                ->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                        'created_at' => $payment->created_at->toISOString(),
                        'shoot' => $payment->shoot ? [
                            'id' => $payment->shoot->id,
                            'address' => optional($payment->shoot)->address,
                        ] : null,
                    ];
                })
                ->toArray();
        }

        return $sharedData;
    }
    /**
     * Optimized version of presentUserForViewer that uses pre-loaded data
     */
    protected function presentUserForViewerOptimized(
        User $user, 
        User $viewer, 
        $allLinks, 
        $shootCounts, 
        $totalSpent
    ): array {
        $payload = $user->attributesToArray();
        $payload['service_groups'] = $this->serializeServiceGroups($user);
        $payload['service_group_ids'] = array_map(
            fn ($group) => (string) $group['id'],
            $payload['service_groups']
        );
        
        // Map database fields to frontend field names
        if (isset($payload['phonenumber'])) {
            $payload['phone'] = $payload['phonenumber'];
            $payload['phone_number'] = $payload['phonenumber'];
        }
        if (isset($payload['company_name'])) {
            $payload['company'] = $payload['company_name'];
        }
        $payload['shoot_cc_emails'] = $this->sanitizeShootCcEmails($payload['shoot_cc_emails'] ?? []);
        $payload['shootCcEmails'] = $payload['shoot_cc_emails'];
        $payload['email_health'] = $user->email_health;
        $payload = app(PhotographerAddressPolicy::class)->presentSubjectForViewer($payload, $viewer, $user);
        if (array_key_exists('zip', $payload)) {
            $payload['zipcode'] = $payload['zip'];
        }
        $payload['client_discount_type'] = $payload['client_discount_type'] ?? null;
        $payload['client_discount_value'] = isset($payload['client_discount_value']) && $payload['client_discount_value'] !== null
            ? (float) $payload['client_discount_value']
            : null;
        $payload['clientDiscountType'] = $payload['client_discount_type'];
        $payload['clientDiscountValue'] = $payload['client_discount_value'];
        
        // Get linked accounts from pre-loaded data
        $userLinks = $allLinks->get($user->id, collect());
        $linkedAccounts = [];
        
        foreach ($userLinks as $link) {
            $linkedUser = $link->main_account_id === $user->id 
                ? $link->linkedAccount 
                : $link->mainAccount;

            if ($linkedUser) {
                $linkedAccounts[] = [
                    'id' => $linkedUser->id,
                    'name' => $linkedUser->name,
                    'email' => $linkedUser->email,
                    'role' => $linkedUser->role,
                    'account_status' => $linkedUser->account_status ?? 'active',
                    'sharedDetails' => $link->getFormattedSharedDetails(),
                    'linkedAt' => $link->linked_at->toISOString(),
                    'linkId' => $link->id,
                ];
            }
        }
        
        // Use pre-loaded counts instead of querying
        $payload['linkedAccounts'] = $linkedAccounts;
        $payload['totalShoots'] = $shootCounts->get($user->id, 0);
        $payload['totalSpent'] = (float) ($totalSpent->get($user->id, 0) ?? 0);
        $payload['linkedProperties'] = [];
        
        // Simplified shared data (skip expensive property grouping for list view)
        $payload['sharedData'] = [
            'totalShoots' => $payload['totalShoots'],
            'totalSpent' => $payload['totalSpent'],
            'properties' => [],
            'paymentHistory' => [],
            'lastActivity' => null,
            'communicationHistory' => [
                'emails' => [],
                'sms' => [],
                'calls' => [],
                'notes' => [],
            ],
        ];
        
        if (!$this->viewerIsSuperAdmin($viewer)) {
            if (is_array($payload['metadata'])) {
                Arr::forget($payload['metadata'], 'repDetails.homeAddress');
                Arr::forget($payload['metadata'], 'repDetails.commissionPercentage');
            }
        }

        return $payload;
    }

    protected function serializeServiceGroups(User $user): array
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return [];
        }

        $groups = $user->relationLoaded('serviceGroups')
            ? $user->serviceGroups
            : $user->serviceGroups()->get(['service_groups.id', 'service_groups.name', 'service_groups.description']);

        return $groups->map(function ($group) {
            return [
                'id' => (string) $group->id,
                'name' => $group->name,
                'description' => $group->description,
            ];
        })->values()->all();
    }

    protected function getDefaultServiceGroupIds(): array
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return [];
        }

        $defaultGroup = ServiceGroup::getDefaultGroup();

        return $defaultGroup ? [(int) $defaultGroup->id] : [];
    }

    protected function serviceGroupsFeatureAvailable(): bool
    {
        try {
            if (!class_exists(ServiceGroup::class)) {
                return false;
            }

            return ServiceGroup::isFeatureAvailable();
        } catch (\Throwable $exception) {
            \Log::warning('Service groups unavailable in UserController.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function generateUniqueUsername(?string $seed): string
    {
        $base = Str::slug($seed ?? 'user', '_');
        if (empty($base)) {
            $base = 'user';
        }

        $username = $base;
        $counter = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base . '_' . $counter;
            $counter++;
            if ($counter > 50) {
                $username = $base . '_' . Str::lower(Str::random(5));
                break;
            }
        }

        return $username;
    }

    protected function logUserActivity(
        User $user,
        string $eventType,
        string $title,
        ?string $description = null,
        ?User $actor = null,
        array $metadata = []
    ): void {
        try {
            UserActivityLog::record($user, $eventType, $title, $description, $actor, $metadata);
        } catch (\Throwable $exception) {
            \Log::warning('Unable to persist user activity log.', [
                'user_id' => $user->id,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function summarizeChangedAttributes(array $fields): string
    {
        $labels = collect($fields)
            ->map(fn ($field) => $this->formatFieldLabel($field))
            ->filter()
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return 'Account details were updated.';
        }

        return 'Updated: ' . $labels->implode(', ') . '.';
    }

    protected function formatFieldLabel(string $field): string
    {
        return match ($field) {
            'name' => 'name',
            'email' => 'email',
            'phonenumber' => 'phone',
            'company_name' => 'company',
            'address' => 'address',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'zip code',
            'license_number' => 'license number',
            'company_notes' => 'company notes',
            'shoot_cc_emails' => 'shoot CC emails',
            'client_discount_type', 'client_discount_value' => 'client discount',
            'role' => 'role',
            'secondary_roles' => 'secondary roles',
            'bio' => 'bio',
            'avatar' => 'avatar',
            'metadata' => 'profile preferences',
            'timezone' => 'timezone',
            'facebook_url', 'twitter_url', 'linkedin_url', 'pinterest_url' => 'social links',
            'service_group_ids' => 'service groups',
            default => Str::of($field)->replace('_', ' ')->lower()->toString(),
        };
    }

    protected function formatRoleLabel(?string $role): string
    {
        $value = (string) $role;
        if ($value === '') {
            return 'User';
        }

        return Str::of($value)
            ->replace('_', ' ')
            ->replaceMatches('/([a-z])([A-Z])/', '$1 $2')
            ->title()
            ->toString();
    }

    protected function getUserLastLoginAt(User $user): ?\Illuminate\Support\Carbon
    {
        $explicitLogin = UserActivityLog::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'login')
            ->latest('occurred_at')
            ->value('occurred_at');

        if ($explicitLogin) {
            return \Illuminate\Support\Carbon::parse($explicitLogin);
        }

        $sessionLogin = DB::table('system_overview_sessions')
            ->where('user_id', $user->id)
            ->whereNotNull('started_at')
            ->orderByDesc('started_at')
            ->value('started_at');

        return $sessionLogin ? \Illuminate\Support\Carbon::parse($sessionLogin) : null;
    }

    protected function getUserActivityTimeline(User $user, int $limit = 100): array
    {
        $normalizedLimit = max(1, min($limit, 250));

        return collect()
            ->merge($this->getExplicitUserActivityEntries($user))
            ->merge($this->getLifecycleActivityEntries($user))
            ->merge($this->getSessionActivityEntries($user))
            ->merge($this->getRouteActivityEntries($user))
            ->merge($this->getAccountLinkActivityEntries($user))
            ->merge($this->getShootRelatedActivityEntries($user))
            ->merge($this->getMessageActivityEntries($user))
            ->filter(fn ($entry) => !empty($entry['timestamp']) && !empty($entry['title']))
            ->sortByDesc('timestamp')
            ->unique(fn ($entry) => implode('|', [
                $entry['type'] ?? '',
                $entry['title'] ?? '',
                $entry['description'] ?? '',
                $entry['timestamp'] ?? '',
            ]))
            ->take($normalizedLimit)
            ->values()
            ->all();
    }

    protected function getExplicitUserActivityEntries(User $user): array
    {
        return UserActivityLog::query()
            ->where('user_id', $user->id)
            ->latest('occurred_at')
            ->take(100)
            ->get()
            ->map(fn (UserActivityLog $log) => $this->buildActivityEntry(
                'audit-' . $log->id,
                $log->event_type,
                $log->title,
                $log->description,
                optional($log->occurred_at)->toIso8601String(),
                'audit',
                $log->metadata ?? []
            ))
            ->all();
    }

    protected function getLifecycleActivityEntries(User $user): array
    {
        $entries = [];
        $hasCreatedAudit = UserActivityLog::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'account_created')
            ->exists();

        if (!$hasCreatedAudit && $user->created_at) {
            $creator = $user->created_by_name ? ' by ' . $user->created_by_name : '';
            $entries[] = $this->buildActivityEntry(
                'lifecycle-created-' . $user->id,
                'account_created',
                'Account created',
                sprintf('Created as %s%s.', $this->formatRoleLabel($user->role), $creator),
                $user->created_at->toIso8601String(),
                'lifecycle'
            );
        }

        $hasUpdatedAudit = UserActivityLog::query()
            ->where('user_id', $user->id)
            ->whereIn('event_type', ['account_updated', 'profile_updated'])
            ->exists();

        if (
            !$hasUpdatedAudit
            && $user->updated_at
            && $user->created_at
            && $user->updated_at->gt($user->created_at)
        ) {
            $entries[] = $this->buildActivityEntry(
                'lifecycle-updated-' . $user->id,
                'account_updated',
                'Account details updated',
                'Profile details were changed.',
                $user->updated_at->toIso8601String(),
                'lifecycle'
            );
        }

        return $entries;
    }

    protected function getSessionActivityEntries(User $user): array
    {
        return collect(DB::table('system_overview_sessions')
            ->where('user_id', $user->id)
            ->whereNotNull('started_at')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get())
            ->map(function ($session) {
                $route = trim((string) ($session->current_route ?? ''));
                $description = $route !== '' ? 'Session started on ' . $route . '.' : 'Dashboard session started.';

                return $this->buildActivityEntry(
                    'session-' . $session->id,
                    'login',
                    'User logged in',
                    $description,
                    $session->started_at ? \Illuminate\Support\Carbon::parse($session->started_at)->toIso8601String() : null,
                    'session',
                    [
                        'route' => $route,
                        'last_activity_at' => $session->last_activity_at,
                    ]
                );
            })
            ->all();
    }

    protected function getRouteActivityEntries(User $user): array
    {
        return collect(DB::table('system_overview_route_events')
            ->where('user_id', $user->id)
            ->where('event_type', '!=', 'heartbeat')
            ->orderByDesc('occurred_at')
            ->limit(40)
            ->get())
            ->map(function ($event) {
                $route = trim((string) ($event->route_path ?? ''));
                $pageKey = trim((string) ($event->page_key ?? ''));
                $component = trim((string) ($event->component_name ?? ''));
                $actionName = trim((string) ($event->action_name ?? ''));

                $title = match ($event->event_type) {
                    'component_mount' => 'Opened page component',
                    'blocker' => 'Workflow blocker encountered',
                    'error' => 'System error encountered',
                    default => Str::of((string) $event->event_type)->replace('_', ' ')->title()->toString(),
                };

                $details = collect([
                    $route !== '' ? 'Route ' . $route : null,
                    $pageKey !== '' ? 'Page ' . $pageKey : null,
                    $component !== '' ? 'Component ' . $component : null,
                    $actionName !== '' ? 'Action ' . $actionName : null,
                ])->filter()->implode(' · ');

                return $this->buildActivityEntry(
                    'route-' . $event->id,
                    'route_' . $event->event_type,
                    $title,
                    $details !== '' ? $details . '.' : null,
                    $event->occurred_at ? \Illuminate\Support\Carbon::parse($event->occurred_at)->toIso8601String() : null,
                    'telemetry'
                );
            })
            ->all();
    }

    protected function getAccountLinkActivityEntries(User $user): array
    {
        return AccountLink::query()
            ->where(function ($query) use ($user) {
                $query->where('main_account_id', $user->id)
                    ->orWhere('linked_account_id', $user->id);
            })
            ->with(['mainAccount:id,name', 'linkedAccount:id,name'])
            ->orderByDesc('linked_at')
            ->limit(20)
            ->get()
            ->map(function (AccountLink $link) use ($user) {
                $otherAccount = (int) $link->main_account_id === (int) $user->id
                    ? $link->linkedAccount
                    : $link->mainAccount;

                return $this->buildActivityEntry(
                    'link-' . $link->id,
                    'account_linked',
                    'Account linked',
                    $otherAccount ? 'Linked with ' . $otherAccount->name . '.' : 'Account link was created.',
                    optional($link->linked_at ?? $link->created_at)?->toIso8601String(),
                    'link',
                    [
                        'shared_details' => $link->shared_details ?? [],
                    ]
                );
            })
            ->all();
    }

    protected function getShootRelatedActivityEntries(User $user): array
    {
        $shoots = \App\Models\Shoot::query()
            ->where(function ($query) use ($user) {
                $query->where('client_id', $user->id)
                    ->orWhere('photographer_id', $user->id)
                    ->orWhere('editor_id', $user->id)
                    ->orWhere('rep_id', $user->id);
            })
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get([
                'id',
                'client_id',
                'photographer_id',
                'editor_id',
                'rep_id',
                'address',
                'created_at',
                'updated_at',
                'workflow_status',
                'photos_uploaded_at',
                'editing_completed_at',
                'completed_at',
                'admin_verified_at',
            ]);

        $entries = collect();

        foreach ($shoots as $shoot) {
            $address = trim((string) ($shoot->address ?? 'Shoot #' . $shoot->id));

            if ((int) $shoot->client_id === (int) $user->id) {
                $entries->push($this->buildActivityEntry(
                    'shoot-client-' . $shoot->id,
                    'shoot_booked',
                    'Shoot booked',
                    sprintf('Shoot #%s at %s.', $shoot->id, $address),
                    optional($shoot->created_at)?->toIso8601String(),
                    'shoot'
                ));
            }

            if ((int) $shoot->photographer_id === (int) $user->id) {
                $entries->push($this->buildActivityEntry(
                    'shoot-photographer-' . $shoot->id,
                    'shoot_assigned',
                    'Assigned as photographer',
                    sprintf('Shoot #%s at %s.', $shoot->id, $address),
                    optional($shoot->created_at)?->toIso8601String(),
                    'shoot'
                ));
            }

            if ((int) $shoot->editor_id === (int) $user->id) {
                $entries->push($this->buildActivityEntry(
                    'shoot-editor-' . $shoot->id,
                    'editing_assigned',
                    'Assigned as editor',
                    sprintf('Shoot #%s at %s.', $shoot->id, $address),
                    optional($shoot->created_at)?->toIso8601String(),
                    'shoot'
                ));
            }

            if ((int) $shoot->rep_id === (int) $user->id) {
                $entries->push($this->buildActivityEntry(
                    'shoot-rep-' . $shoot->id,
                    'rep_assigned',
                    'Assigned as sales rep',
                    sprintf('Shoot #%s at %s.', $shoot->id, $address),
                    optional($shoot->created_at)?->toIso8601String(),
                    'shoot'
                ));
            }

            $milestoneAt = $shoot->admin_verified_at
                ?? $shoot->completed_at
                ?? $shoot->editing_completed_at
                ?? $shoot->photos_uploaded_at;

            if ($milestoneAt && optional($shoot->created_at)?->ne($milestoneAt)) {
                $entries->push($this->buildActivityEntry(
                    'shoot-stage-' . $shoot->id,
                    'shoot_stage_' . ($shoot->workflow_status ?? 'updated'),
                    'Shoot reached ' . $this->formatRoleLabel((string) ($shoot->workflow_status ?? 'updated')) . ' stage',
                    sprintf('Shoot #%s at %s.', $shoot->id, $address),
                    optional($milestoneAt)?->toIso8601String(),
                    'shoot'
                ));
            }
        }

        $shootIds = $shoots->pluck('id')->values();
        if ($shootIds->isNotEmpty()) {
            $entries = $entries->merge(
                \App\Models\ShootActivityLog::query()
                    ->whereIn('shoot_id', $shootIds)
                    ->with('shoot:id,address')
                    ->latest('created_at')
                    ->limit(40)
                    ->get()
                    ->map(function (\App\Models\ShootActivityLog $log) {
                        $title = Str::of((string) $log->action)->replace('_', ' ')->title()->toString();
                        $shootLabel = $log->shoot ? 'Shoot #' . $log->shoot_id . ' at ' . ($log->shoot->address ?? 'unknown address') : 'Shoot #' . $log->shoot_id;
                        $description = $log->description ?: $shootLabel . '.';

                        return $this->buildActivityEntry(
                            'shoot-log-' . $log->id,
                            'shoot_activity_' . $log->action,
                            $title,
                            $description,
                            optional($log->created_at)?->toIso8601String(),
                            'shoot',
                            $log->metadata ?? []
                        );
                    })
            );
        }

        return $entries->all();
    }

    protected function getMessageActivityEntries(User $user): array
    {
        $email = strtolower(trim((string) $user->email));
        $phone = preg_replace('/\D+/', '', (string) ($user->phone ?? $user->phonenumber ?? ''));

        $query = \App\Models\Message::query()
            ->where(function ($builder) use ($user, $email, $phone) {
                $builder->where('related_account_id', $user->id)
                    ->orWhere('created_by', $user->id)
                    ->orWhere('sender_user_id', $user->id);

                if ($email !== '') {
                    $builder->orWhereRaw('LOWER(COALESCE(to_address, "")) = ?', [$email])
                        ->orWhereRaw('LOWER(COALESCE(from_address, "")) = ?', [$email]);
                }

                if ($phone !== '') {
                    $builder->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(to_address, ""), "+", ""), "-", ""), "(", ""), ")", ""), " ", "") = ?', [$phone])
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(from_address, ""), "+", ""), "-", ""), "(", ""), ")", ""), " ", "") = ?', [$phone]);
                }
            })
            ->latest('created_at')
            ->limit(25);

        return $query->get()
            ->map(function (\App\Models\Message $message) {
                $isOutbound = strtoupper((string) $message->direction) === 'OUTBOUND';
                $channel = strtoupper((string) $message->channel) === 'SMS' ? 'Text message' : 'Email';
                $title = $channel . ' ' . ($isOutbound ? 'sent' : 'received');
                $counterparty = $isOutbound ? ($message->to_address ?: 'recipient') : ($message->from_address ?: 'sender');
                $description = collect([
                    $counterparty ? 'With ' . $counterparty : null,
                    $message->subject ? 'Subject: ' . $message->subject : null,
                    $message->status ? 'Status: ' . $message->status : null,
                ])->filter()->implode(' · ');

                return $this->buildActivityEntry(
                    'message-' . $message->id,
                    'message_' . strtolower((string) $message->channel) . '_' . strtolower((string) $message->direction),
                    $title,
                    $description !== '' ? $description . '.' : null,
                    optional($message->created_at)?->toIso8601String(),
                    'messaging'
                );
            })
            ->all();
    }

    protected function buildActivityEntry(
        string $id,
        string $type,
        string $title,
        ?string $description,
        ?string $timestamp,
        string $source,
        array $metadata = []
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'timestamp' => $timestamp,
            'source' => $source,
            'metadata' => $metadata,
        ];
    }

    /**
     * Delete a user (Super Admin only)
     */
    public function destroy(Request $request, $id)
    {
        $viewer = $request->user();
        
        // Only superadmin can delete users
        if ($viewer->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized. Only Super Admin can delete users.'], 403);
        }

        $user = User::find($id);
        
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Prevent self-deletion
        if ($user->id === $viewer->id) {
            return response()->json(['message' => 'Cannot delete your own account'], 400);
        }

        // Prevent deletion of superadmin accounts
        if ($user->role === 'superadmin') {
            return response()->json(['message' => 'Cannot delete Super Admin accounts'], 400);
        }

        $deletedUser = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];

        $this->logUserActivity(
            $user,
            'account_deleted',
            'Account deleted',
            sprintf('Account deleted by %s.', $viewer->name),
            $viewer,
            $deletedUser
        );

        // Route through the canonical lifecycle service so deletion ALSO revokes
        // auth tokens, purges active sessions, and busts cached directory lists in
        // the same request (QA #15) — instead of a bare soft delete that leaves a
        // valid token and a stale `photographers_list_v3` cache behind.
        try {
            app(\App\Services\AccountStatusService::class)
                ->setStatus($user, \App\Services\AccountStatusService::STATUS_DELETED, $viewer);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'message' => 'User deleted successfully',
            'user' => $deletedUser,
        ]);
    }

    private function normalizePhotographerEquipmentPayload(mixed $rawEquipment): array
    {
        if (is_string($rawEquipment)) {
            $decoded = json_decode($rawEquipment, true);
            $rawEquipment = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (!is_array($rawEquipment)) {
            return [];
        }

        return collect($rawEquipment)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $issueDate = trim((string) ($item['issue_date'] ?? $item['issueDate'] ?? ''));

                return [
                    'name' => trim((string) ($item['name'] ?? '')),
                    'serial_number' => trim((string) ($item['serial_number'] ?? $item['serialNumber'] ?? '')) ?: null,
                    'issue_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) ? $issueDate : null,
                ];
            })
            ->filter(fn (array $item) => $item['name'] !== '')
            ->values()
            ->all();
    }

    private function createPhotographerEquipmentFromRequest(Request $request, User $photographer, User $admin, array $equipmentPayload, array $existingEquipmentIds = []): int
    {
        $created = 0;

        if ($existingEquipmentIds !== []) {
            $assigned = PhotographerEquipment::query()
                ->whereIn('id', $existingEquipmentIds)
                ->whereNull('photographer_id')
                ->get();

            foreach ($assigned as $equipment) {
                $equipment->forceFill([
                    'photographer_id' => $photographer->id,
                    'status' => PhotographerEquipment::STATUS_PENDING,
                    'verification_requested_at' => null,
                    'submitted_at' => null,
                    'verified_at' => null,
                    'verified_by' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ])->save();
                $created++;
            }
        }

        foreach ($equipmentPayload as $index => $item) {
            $equipment = PhotographerEquipment::create([
                'photographer_id' => $photographer->id,
                'name' => $item['name'],
                'serial_number' => $item['serial_number'] ?? null,
                'issue_date' => $item['issue_date'] ?? null,
                'status' => PhotographerEquipment::STATUS_PENDING,
            ]);

            $this->storePhotographerEquipmentPhotos(
                $equipment,
                $request->file("equipment_reference_photos.{$index}", []),
                $admin
            );

            $created++;
        }

        return $created;
    }

    /**
     * @param array<int, UploadedFile>|UploadedFile|null $files
     */
    private function storePhotographerEquipmentPhotos(PhotographerEquipment $equipment, array|UploadedFile|null $files, User $uploadedBy): void
    {
        $files = $files instanceof UploadedFile ? [$files] : ($files ?: []);

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store(
                "photographer-equipment/{$equipment->id}/" . PhotographerEquipmentPhoto::TYPE_ADMIN_REFERENCE,
                'local'
            );

            $equipment->photos()->create([
                'uploaded_by' => $uploadedBy->id,
                'type' => PhotographerEquipmentPhoto::TYPE_ADMIN_REFERENCE,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }

    private function photographerEquipmentTablesReady(): bool
    {
        return Schema::hasTable('photographer_equipments')
            && Schema::hasTable('photographer_equipment_photos');
    }
}
