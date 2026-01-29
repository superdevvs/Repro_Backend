<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AccountLink;
use App\Services\Messaging\AutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;


class UserController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'salesRep'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Optimize: Eager load relationships and batch queries
        if ($user->role === 'salesRep') {
            $repId = $user->id;
            $clientIdsFromShoots = \App\Models\Shoot::where('rep_id', $repId)
                ->pluck('client_id')
                ->unique()
                ->toArray();

            $users = User::where('role', 'client')->get()->filter(function (User $client) use ($repId, $clientIdsFromShoots) {
                $metadata = $client->metadata ?? [];
                $repCandidate = null;
                if (is_array($metadata) && !empty($metadata)) {
                    $repCandidate = $metadata['accountRepId']
                        ?? $metadata['account_rep_id']
                        ?? $metadata['repId']
                        ?? $metadata['rep_id']
                        ?? null;
                }

                if ($repCandidate !== null && (string) $repCandidate === (string) $repId) {
                    return true;
                }

                if (isset($client->created_by_id) && $client->created_by_id !== null && (string) $client->created_by_id === (string) $repId) {
                    return true;
                }

                return in_array($client->id, $clientIdsFromShoots, true);
            })->values();
        } else {
            $users = User::all();
        }

        if ($users->isEmpty()) {
            return response()->json(['users' => []]);
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

     public function store(Request $request)
    {
        $admin = $request->user();

        if (!in_array($admin->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'username' => 'nullable|string|unique:users',
            'phone_number' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip' => 'nullable|string|max:20',
            'license_number' => 'nullable|string|max:100',
            'company_notes' => 'nullable|string',
            'role' => 'required|in:superadmin,admin,editing_manager,client,photographer,editor,salesRep',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|image|max:2048',
            'metadata' => 'nullable',
            'created_by_name' => 'nullable|string|max:255',
            'created_by_id' => 'nullable|integer|exists:users,id',
            'pilotLicenseFile' => 'nullable|string|url',
            'pilotLicenseFileName' => 'nullable|string|max:255',
            'insuranceNumber' => 'nullable|string|max:255',
            'insuranceFile' => 'nullable|string|url',
            'insuranceFileName' => 'nullable|string|max:255',
            'specialties' => 'nullable|string',
        ]);

        if (array_key_exists('phone_number', $validated)) {
            $validated['phonenumber'] = $validated['phone_number'];
            unset($validated['phone_number']);
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

        $user = User::create($validated);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $this->presentUserForViewer($user, $admin),
        ], 201);
    }

    public function getClients()
    {
        $clients = User::where('role', 'client')->get()->map(function ($client) {
            $clientData = $client->toArray();
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
            
            return $clientData;
        });

        return response()->json([
            'status' => 'success',
            'data' => $clients
        ]);
    }

    public function getPhotographers()
    {
        $photographers = User::where('role', 'photographer')->get();

        return response()->json([
            'status' => 'success',
            'data' => $photographers
        ]);
    }

    // Lightweight public list (id + name + email) for UI dropdowns
    public function simplePhotographers()
    {
        $photographers = \Illuminate\Support\Facades\Cache::remember('photographers_list', 300, function () {
            return User::where('role', 'photographer')
                ->select('id', 'name', 'email')
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
        if (!in_array($admin->role, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'username' => 'nullable|string|unique:users,username,' . $id,
            'phone_number' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip' => 'nullable|string|max:20',
            'license_number' => 'nullable|string|max:100',
            'company_notes' => 'nullable|string',
            'role' => 'sometimes|in:superadmin,admin,editing_manager,client,photographer,editor,salesRep',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string',
            'metadata' => 'nullable',
            'created_by_name' => 'nullable|string|max:255',
            'created_by_id' => 'nullable|integer|exists:users,id',
            'pilotLicenseFile' => 'nullable|string|url',
            'pilotLicenseFileName' => 'nullable|string|max:255',
            'insuranceNumber' => 'nullable|string|max:255',
            'insuranceFile' => 'nullable|string|url',
            'insuranceFileName' => 'nullable|string|max:255',
            'specialties' => 'nullable|string',
        ]);

        if (array_key_exists('phone_number', $validated)) {
            $validated['phonenumber'] = $validated['phone_number'];
            unset($validated['phone_number']);
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

        // Add photographer-specific fields to metadata
        if ($user->role === 'photographer' || ($request->has('role') && $request->input('role') === 'photographer')) {
            $photographerData = $user->metadata ?? [];
            
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
            
            $validated['metadata'] = array_merge($validated['metadata'] ?? $user->metadata ?? [], $photographerData);
        }

        // Update user
        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $this->presentUserForViewer($user, $admin),
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
        ]);

        $user = User::findOrFail($id);
        $oldRole = $user->role;
        $user->role = $validated['role'];
        $changed = $user->isDirty('role');
        $user->save();

        return response()->json([
            'message' => $changed ? 'Role updated successfully.' : 'Role unchanged.',
            'changed' => $changed,
            'user' => $user,
            'old_role' => $oldRole,
            'new_role' => $user->role,
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
            'password' => 'required|string|min:6',
        ]);

        $user = User::findOrFail($id);
        $user->password = Hash::make($validated['password']);
        $user->save();

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

        return response()->json([
            'message' => 'Password updated successfully.',
            'user_id' => $user->id,
        ]);
    }

    protected function presentUserForViewer(User $user, User $viewer): array
    {
        $payload = $user->attributesToArray();
        
        // Map database fields to frontend field names
        if (isset($payload['phonenumber'])) {
            $payload['phone'] = $payload['phonenumber'];
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
        $accountIds = array_merge([$user->id], array_column($linkedAccounts, 'id'));

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

        $links = \App\Models\AccountLink::forAccount($user->id)->active()->get();
        $canSeeShoots = $links->contains(fn ($link) => $link->sharesDetail('shoots'));
        $canSeeInvoices = $links->contains(fn ($link) => $link->sharesDetail('invoices'));

        if ($canSeeShoots) {
            $shootQuery = \App\Models\Shoot::whereIn('client_id', $accountIds);
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
            $lastShoot = \App\Models\Shoot::whereIn('client_id', $accountIds)
                ->orderBy('updated_at', 'desc')
                ->first();
            $sharedData['lastActivity'] = $lastShoot?->updated_at?->toISOString();
        }

        if ($canSeeInvoices) {
            $sharedData['totalSpent'] = \App\Models\Shoot::whereIn('client_id', $accountIds)->sum('total_quote') ?? 0;

            $sharedData['paymentHistory'] = \App\Models\Payment::whereIn('user_id', $accountIds)
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
        
        // Map database fields to frontend field names
        if (isset($payload['phonenumber'])) {
            $payload['phone'] = $payload['phonenumber'];
        }
        if (isset($payload['company_name'])) {
            $payload['company'] = $payload['company_name'];
        }
        
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

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
            'user' => $deletedUser,
        ]);
    }
}
