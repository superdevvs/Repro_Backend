<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountLink;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\RolePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AccountLinkController extends Controller
{
    private const OWNER_ROLES = ['admin', 'superadmin', 'client'];

    public function __construct(
        private readonly RolePermissionService $permissions,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAdminAction($request, 'view')) {
            return $response;
        }

        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;

        $links = AccountLink::with(['mainAccount', 'linkedAccount'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->get();

        if ($viewer && $this->isSalesRepUser($viewer)) {
            $links = $links
                ->filter(fn (AccountLink $link) => $this->salesRepCanManageLink($viewer, $link, $salesRepScope))
                ->values();
        }

        return response()->json([
            'success' => true,
            'links' => $links->map(fn (AccountLink $link) => $this->serializeLink($link))->values(),
            'total' => $links->count(),
            'summary' => [
                'owners' => $links->pluck('main_account_id')->unique()->count(),
                'linkedClients' => $links->pluck('linked_account_id')->unique()->count(),
                'active' => $links->where('status', 'active')->count(),
                'inactive' => $links->where('status', 'inactive')->count(),
                'suspended' => $links->where('status', 'suspended')->count(),
                'attention' => $links->where('status', '!=', 'active')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAdminAction($request, 'update')) {
            return $response;
        }

        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;

        $validated = $request->validate([
            'mainAccountId' => 'required|exists:users,id',
            'clientAccountId' => 'required|exists:users,id|different:mainAccountId',
            'sharedDetails' => 'required|array',
            'notes' => 'nullable|string|max:500',
        ]);

        $mainAccount = User::findOrFail($validated['mainAccountId']);
        $clientAccount = User::findOrFail($validated['clientAccountId']);
        $sharedDetails = AccountLink::normalizeSharedDetails($validated['sharedDetails']);
        $notes = $validated['notes'] ?? null;

        if ($viewer && $this->isSalesRepUser($viewer)) {
            if ($response = $this->ensureSalesRepCanManageRelationship($viewer, $mainAccount, $clientAccount, $salesRepScope)) {
                return $response;
            }
        }

        if ($response = $this->validateRelationship($mainAccount, $clientAccount)) {
            return $response;
        }

        $result = $this->createOrReactivateLink($mainAccount, $clientAccount, $sharedDetails, $notes);
        if ($result['result'] === 'skipped') {
            return response()->json([
                'success' => false,
                'message' => 'These accounts are already linked.',
                'link' => $this->serializeLink($result['link']),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['result'] === 'reactivated'
                ? 'Account link reactivated successfully.'
                : 'Accounts linked successfully.',
            'result' => $result['result'],
            'link' => $this->serializeLink($result['link']),
        ], $result['result'] === 'created' ? 201 : 200);
    }

    public function batchStore(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAdminAction($request, 'update')) {
            return $response;
        }

        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;

        $validated = $request->validate([
            'mainAccountId' => 'required|exists:users,id',
            'clientAccountIds' => 'required|array|min:1',
            'clientAccountIds.*' => 'exists:users,id|different:mainAccountId',
            'sharedDetails' => 'required|array',
            'notes' => 'nullable|string|max:500',
        ]);

        $mainAccount = User::findOrFail($validated['mainAccountId']);
        $sharedDetails = AccountLink::normalizeSharedDetails($validated['sharedDetails']);
        $notes = $validated['notes'] ?? null;

        if ($viewer && $this->isSalesRepUser($viewer) && !$this->salesRepOwnsClient($viewer, $mainAccount, $salesRepScope)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only link client accounts within your assigned sales-rep scope.',
            ], 403);
        }

        if ($response = $this->validateRelationshipOwner($mainAccount)) {
            return $response;
        }

        $created = [];
        $reactivated = [];
        $skipped = [];
        $errors = [];

        foreach (collect($validated['clientAccountIds'])->unique()->values() as $clientId) {
            try {
                $clientAccount = User::findOrFail($clientId);

                if ($viewer && $this->isSalesRepUser($viewer) && !$this->salesRepOwnsClient($viewer, $clientAccount, $salesRepScope)) {
                    $errors[] = [
                        'accountId' => (string) $clientId,
                        'message' => 'This client is outside your assigned sales-rep scope.',
                    ];
                    continue;
                }

                if ($response = $this->validateRelationship($mainAccount, $clientAccount)) {
                    $payload = $response->getData(true);
                    $errors[] = [
                        'accountId' => (string) $clientId,
                        'message' => $payload['message'] ?? 'Invalid account relationship.',
                    ];
                    continue;
                }

                $result = $this->createOrReactivateLink($mainAccount, $clientAccount, $sharedDetails, $notes);
                $serialized = $this->serializeLink($result['link']);

                if ($result['result'] === 'created') {
                    $created[] = $serialized;
                    continue;
                }

                if ($result['result'] === 'reactivated') {
                    $reactivated[] = $serialized;
                    continue;
                }

                $skipped[] = [
                    'accountId' => $serialized['accountId'],
                    'accountName' => $serialized['accountName'],
                    'reason' => 'Already linked',
                    'link' => $serialized,
                ];
            } catch (\Throwable $exception) {
                $errors[] = [
                    'accountId' => (string) $clientId,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => $this->buildBatchMessage($created, $reactivated, $skipped, $errors),
            'created' => $created,
            'reactivated' => $reactivated,
            'skipped' => $skipped,
            'errors' => $errors,
            'summary' => [
                'total' => count($validated['clientAccountIds']),
                'created' => count($created),
                'reactivated' => count($reactivated),
                'skipped' => count($skipped),
                'failed' => count($errors),
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($response = $this->authorizeAdminAction($request, 'update')) {
            return $response;
        }

        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;

        $link = AccountLink::with(['mainAccount', 'linkedAccount'])->findOrFail($id);

        if ($viewer && $this->isSalesRepUser($viewer) && !$this->salesRepCanManageLink($viewer, $link, $salesRepScope)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only manage linked accounts within your assigned sales-rep scope.',
            ], 403);
        }

        $validated = $request->validate([
            'sharedDetails' => 'required|array',
            'notes' => 'nullable|string|max:500',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        $status = $validated['status'] ?? $link->status;

        $link->update([
            'shared_details' => AccountLink::normalizeSharedDetails($validated['sharedDetails']),
            'notes' => $validated['notes'] ?? $link->notes,
            'status' => $status,
            'linked_at' => $status === 'active' ? now() : $link->linked_at,
            'unlinked_at' => $status === 'active' ? null : now(),
        ]);

        $link->refresh()->loadMissing(['mainAccount', 'linkedAccount']);

        return response()->json([
            'success' => true,
            'message' => 'Account link updated successfully.',
            'link' => $this->serializeLink($link),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($response = $this->authorizeAdminAction($request, 'update')) {
            return $response;
        }

        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;

        $link = AccountLink::with(['mainAccount', 'linkedAccount'])->findOrFail($id);

        if ($viewer && $this->isSalesRepUser($viewer) && !$this->salesRepCanManageLink($viewer, $link, $salesRepScope)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only manage linked accounts within your assigned sales-rep scope.',
            ], 403);
        }

        $link->update([
            'status' => 'inactive',
            'unlinked_at' => now(),
        ]);

        $link->refresh()->loadMissing(['mainAccount', 'linkedAccount']);

        return response()->json([
            'success' => true,
            'message' => 'Account unlinked successfully.',
            'link' => $this->serializeLink($link),
        ]);
    }

    public function forceDestroy(Request $request, string $id): JsonResponse
    {
        if ($response = $this->authorizeAdminAction($request, 'update')) {
            return $response;
        }

        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;

        $link = AccountLink::with(['mainAccount', 'linkedAccount'])->findOrFail($id);

        if ($viewer && $this->isSalesRepUser($viewer) && !$this->salesRepCanManageLink($viewer, $link, $salesRepScope)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only manage linked accounts within your assigned sales-rep scope.',
            ], 403);
        }

        $serialized = $this->serializeLink($link);

        $link->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account link deleted permanently.',
            'link' => $serialized,
        ]);
    }

    public function getSharedData(Request $request, string $accountId): JsonResponse
    {
        if ($response = $this->authorizeAdminAction($request, 'view')) {
            return $response;
        }

        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;

        $account = User::findOrFail($accountId);

        if ($viewer && $this->isSalesRepUser($viewer) && !$this->salesRepOwnsClient($viewer, $account, $salesRepScope)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only view linked accounts within your assigned sales-rep scope.',
            ], 403);
        }

        $links = AccountLink::forAccount($accountId)
            ->active()
            ->with(['mainAccount', 'linkedAccount'])
            ->get();

        if ($viewer && $this->isSalesRepUser($viewer)) {
            $links = $links
                ->filter(fn (AccountLink $link) => $this->salesRepCanManageLink($viewer, $link, $salesRepScope))
                ->values();
        }

        $shootAccountIds = array_merge(
            [(int) $accountId],
            AccountLink::getSharedAccountIdsForDetail((int) $accountId, 'shoots'),
        );
        $invoiceAccountIds = array_merge(
            [(int) $accountId],
            AccountLink::getSharedAccountIdsForDetail((int) $accountId, 'invoices'),
        );

        $sharedData = [
            'linkedAccounts' => $links->map(function (AccountLink $link) use ($accountId) {
                $linkedUser = (string) $link->main_account_id === (string) $accountId
                    ? $link->linkedAccount
                    : $link->mainAccount;

                return [
                    'id' => (string) $linkedUser?->id,
                    'name' => $linkedUser?->name,
                    'email' => $linkedUser?->email,
                    'role' => $linkedUser?->role,
                    'account_status' => $linkedUser?->account_status ?? 'active',
                    'sharedDetails' => $link->getFormattedSharedDetails(),
                    'linkedAt' => $link->linked_at?->toISOString(),
                ];
            })->filter(fn (array $account) => !empty($account['id']))->values()->all(),
            'totalShoots' => 0,
            'totalSpent' => 0,
            'properties' => [],
            'paymentHistory' => [],
            'lastActivity' => null,
        ];

        if (count($shootAccountIds) > 1) {
            $shoots = Shoot::whereIn('client_id', $shootAccountIds)->get();
            $sharedData['totalShoots'] = $shoots->count();
            $sharedData['properties'] = $shoots
                ->groupBy(fn (Shoot $shoot) => strtolower(trim(($shoot->address ?? '') . '|' . ($shoot->city ?? '') . '|' . ($shoot->state ?? ''))))
                ->map(function (Collection $group) {
                    /** @var Shoot $first */
                    $first = $group->first();

                    return [
                        'id' => null,
                        'address' => $first->address ?? '',
                        'city' => $first->city ?? '',
                        'state' => $first->state ?? '',
                        'shootCount' => $group->count(),
                    ];
                })
                ->values()
                ->all();
            $sharedData['lastActivity'] = $shoots->sortByDesc('updated_at')->first()?->updated_at?->toISOString();
        }

        if (count($invoiceAccountIds) > 1) {
            $sharedData['totalSpent'] = Shoot::whereIn('client_id', $invoiceAccountIds)->sum('total_quote') ?? 0;
            $sharedData['paymentHistory'] = Payment::whereHas('shoot', function ($query) use ($invoiceAccountIds) {
                    $query->whereIn('client_id', $invoiceAccountIds);
                })
                ->with('shoot')
                ->orderByDesc('created_at')
                ->take(10)
                ->get()
                ->map(function (Payment $payment) {
                    return [
                        'id' => (string) $payment->id,
                        'amount' => (float) $payment->amount,
                        'status' => $payment->status,
                        'created_at' => $payment->created_at?->toISOString(),
                        'shoot' => $payment->shoot ? [
                            'id' => (string) $payment->shoot->id,
                            'address' => $payment->shoot->address,
                        ] : null,
                    ];
                })
                ->all();
        }

        return response()->json([
            'success' => true,
            'sharedData' => $sharedData,
        ]);
    }

    public function getAvailableAccounts(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAdminAction($request, 'view')) {
            return $response;
        }

        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;

        $legacyRole = $request->string('role')->toString();
        if ($legacyRole !== '') {
            return $this->getLegacyAvailableAccountsResponse($request, $legacyRole);
        }

        $ownerId = $request->string('ownerId')->toString();
        $ownerSearch = trim($request->string('ownerSearch')->toString());
        $clientSearch = trim($request->string('clientSearch')->toString());

        if ($viewer && $this->isSalesRepUser($viewer) && $ownerId !== '') {
            $selectedOwner = User::find($ownerId);
            if (!$selectedOwner || !$this->salesRepOwnsClient($viewer, $selectedOwner, $salesRepScope)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only link client accounts within your assigned sales-rep scope.',
                ], 403);
            }
        }

        $owners = User::query()
            ->whereIn('role', $viewer && $this->isSalesRepUser($viewer) ? ['client'] : self::OWNER_ROLES)
            ->when($ownerSearch !== '', function ($query) use ($ownerSearch) {
                $query->where(function ($nested) use ($ownerSearch) {
                    $nested->where('name', 'like', '%' . $ownerSearch . '%')
                        ->orWhere('email', 'like', '%' . $ownerSearch . '%');
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'avatar', 'account_status'])
            ->when(
                $viewer && $this->isSalesRepUser($viewer),
                fn (Collection $collection) => $collection->filter(
                    fn (User $user) => $this->salesRepOwnsClient($viewer, $user, $salesRepScope)
                )->values(),
            )
            ->map(fn (User $user) => $this->serializeAccountOption($user))
            ->values();

        $clientAccountsQuery = User::query()
            ->where('role', 'client')
            ->when($ownerId !== '', function ($query) use ($ownerId) {
                $query->where('id', '!=', $ownerId)
                    ->whereNotIn('id', AccountLink::query()
                        ->where('main_account_id', $ownerId)
                        ->where('status', 'active')
                        ->pluck('linked_account_id'));
            })
            ->when($clientSearch !== '', function ($query) use ($clientSearch) {
                $query->where(function ($nested) use ($clientSearch) {
                    $nested->where('name', 'like', '%' . $clientSearch . '%')
                        ->orWhere('email', 'like', '%' . $clientSearch . '%')
                        ->orWhere('company_name', 'like', '%' . $clientSearch . '%');
                });
            })
            ->orderBy('name');

        $clientAccounts = $clientAccountsQuery
            ->get(['id', 'name', 'email', 'role', 'avatar', 'account_status', 'company_name'])
            ->when(
                $viewer && $this->isSalesRepUser($viewer),
                fn (Collection $collection) => $collection->filter(
                    fn (User $user) => $this->salesRepOwnsClient($viewer, $user, $salesRepScope)
                )->values(),
            );

        $activeOwnerLinks = AccountLink::query()
            ->active()
            ->whereIn('linked_account_id', $clientAccounts->pluck('id'))
            ->when($ownerId !== '', fn ($query) => $query->where('main_account_id', '!=', $ownerId))
            ->with('mainAccount:id,name,email,role,avatar,account_status')
            ->get()
            ->groupBy('linked_account_id');

        $clientAccounts = $clientAccounts
            ->map(function (User $user) use ($activeOwnerLinks) {
                $conflictingOwners = collect($activeOwnerLinks->get($user->id, collect()))
                    ->map(fn (AccountLink $link) => $this->serializeOwnerSummary($link->mainAccount))
                    ->filter(fn (?array $owner) => !empty($owner['id']))
                    ->values();

                return $this->serializeAccountOption($user, [
                    'isLinkedToOtherOwners' => $conflictingOwners->isNotEmpty(),
                    'activeOwnerLinkCount' => $conflictingOwners->count(),
                    'activeOwnerLinks' => $conflictingOwners->all(),
                ]);
            })
            ->values();

        return response()->json([
            'success' => true,
            'owners' => $owners,
            'clientAccounts' => $clientAccounts,
            'meta' => [
                'ownerId' => $ownerId !== '' ? $ownerId : null,
                'ownerCount' => $owners->count(),
                'clientCount' => $clientAccounts->count(),
            ],
        ]);
    }

    public function hasLinkedAccounts(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'hasLinkedAccounts' => false,
            ], 401);
        }

        $hasLinks = AccountLink::query()
            ->where('linked_account_id', $user->id)
            ->where('status', 'active')
            ->exists();

        $linkedAccounts = [];

        if ($hasLinks) {
            $linkedAccounts = AccountLink::query()
                ->where('linked_account_id', $user->id)
                ->active()
                ->with(['mainAccount', 'linkedAccount'])
                ->get()
                ->map(fn (AccountLink $link) => $this->serializeIncomingOwnerForUser($link))
                ->filter()
                ->values()
                ->all();
        }

        return response()->json([
            'success' => true,
            'hasLinkedAccounts' => $hasLinks,
            'linkedAccounts' => $linkedAccounts,
        ]);
    }

    public function getLinkedAccountsForUser(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $linkedAccounts = AccountLink::query()
            ->where('linked_account_id', $user->id)
            ->active()
            ->with(['mainAccount', 'linkedAccount'])
            ->get()
            ->map(fn (AccountLink $link) => $this->serializeIncomingOwnerForUser($link))
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'linkedAccounts' => $linkedAccounts,
            'total' => count($linkedAccounts),
        ]);
    }

    public function getMySharedData(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'ownerId' => 'required|exists:users,id',
        ]);

        $link = AccountLink::query()
            ->where('main_account_id', $validated['ownerId'])
            ->where('linked_account_id', $user->id)
            ->active()
            ->with(['mainAccount', 'linkedAccount'])
            ->first();

        if (!$link) {
            return response()->json([
                'success' => false,
                'message' => 'This owner is not linked to your account.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'owner' => $this->serializeIncomingOwnerForUser($link),
            'link' => $this->serializeLink($link),
            'sharedData' => $this->buildOwnerScopedSharedData($link),
        ]);
    }

    private function authorizeAdminAction(Request $request, string $action): ?JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        if (!$this->permissions->userCan($user, 'account-linking', $action)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to manage account linking.',
            ], 403);
        }

        return null;
    }

    private function isSalesRepUser(?User $user): bool
    {
        return $user !== null && $this->permissions->normalizedUserRoles($user)->contains('salesRep');
    }

    private function normalizeRole(?string $role): string
    {
        if ($role === null) {
            return '';
        }

        return strtolower(str_replace(['_', '-'], '', $role));
    }

    private function getSalesRepShootScope(User $salesRep): array
    {
        $salesRepShoots = Shoot::query()
            ->where('rep_id', $salesRep->id)
            ->get(['client_id']);

        return [
            'client_ids' => $salesRepShoots
                ->pluck('client_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    private function salesRepOwnsClient(User $salesRep, User $client, ?array $scope = null): bool
    {
        return $this->normalizeRole($client->role) === 'client';
    }

    private function ensureSalesRepCanManageRelationship(User $salesRep, User $mainAccount, User $clientAccount, ?array $scope = null): ?JsonResponse
    {
        if ($this->salesRepOwnsClient($salesRep, $mainAccount, $scope) && $this->salesRepOwnsClient($salesRep, $clientAccount, $scope)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'You can only link client accounts within your assigned sales-rep scope.',
        ], 403);
    }

    private function salesRepCanManageLink(User $salesRep, AccountLink $link, ?array $scope = null): bool
    {
        if (!$link->mainAccount || !$link->linkedAccount) {
            return false;
        }

        return $this->salesRepOwnsClient($salesRep, $link->mainAccount, $scope)
            && $this->salesRepOwnsClient($salesRep, $link->linkedAccount, $scope);
    }

    private function validateRelationshipOwner(User $mainAccount): ?JsonResponse
    {
        if (!in_array($mainAccount->role, self::OWNER_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin, superadmin, and client accounts can own linked client accounts.',
            ], 422);
        }

        return null;
    }

    private function validateRelationship(User $mainAccount, User $clientAccount): ?JsonResponse
    {
        if ($mainAccount->id === $clientAccount->id) {
            return response()->json([
                'success' => false,
                'message' => 'An account cannot be linked to itself.',
            ], 422);
        }

        if ($response = $this->validateRelationshipOwner($mainAccount)) {
            return $response;
        }

        if ($clientAccount->role !== 'client') {
            return response()->json([
                'success' => false,
                'message' => 'Only client accounts can be linked as managed accounts.',
            ], 422);
        }

        return null;
    }

    private function createOrReactivateLink(
        User $mainAccount,
        User $clientAccount,
        array $sharedDetails,
        ?string $notes,
    ): array {
        $existingLink = AccountLink::with(['mainAccount', 'linkedAccount'])
            ->where('main_account_id', $mainAccount->id)
            ->where('linked_account_id', $clientAccount->id)
            ->first();

        if ($existingLink && $existingLink->status === 'active') {
            return [
                'result' => 'skipped',
                'link' => $existingLink,
            ];
        }

        if ($existingLink) {
            $existingLink->update([
                'shared_details' => $sharedDetails,
                'notes' => $notes ?? $existingLink->notes,
                'status' => 'active',
                'linked_at' => now(),
                'unlinked_at' => null,
            ]);

            $existingLink->refresh()->loadMissing(['mainAccount', 'linkedAccount']);

            return [
                'result' => 'reactivated',
                'link' => $existingLink,
            ];
        }

        $link = AccountLink::create([
            'main_account_id' => $mainAccount->id,
            'linked_account_id' => $clientAccount->id,
            'shared_details' => $sharedDetails,
            'notes' => $notes,
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => auth()->id(),
        ]);

        $link->loadMissing(['mainAccount', 'linkedAccount']);

        return [
            'result' => 'created',
            'link' => $link,
        ];
    }

    private function serializeLink(AccountLink $link): array
    {
        $link->loadMissing(['mainAccount', 'linkedAccount']);

        return [
            'id' => (string) $link->id,
            'accountId' => (string) $link->linked_account_id,
            'accountName' => $link->linkedAccount?->name ?? 'Unknown',
            'accountEmail' => $link->linkedAccount?->email ?? '',
            'accountRole' => $link->linkedAccount?->role ?? null,
            'accountAvatar' => $link->linkedAccount?->avatar ?? null,
            'accountStatus' => $link->linkedAccount?->account_status ?? null,
            'mainAccountId' => (string) $link->main_account_id,
            'mainAccountName' => $link->mainAccount?->name ?? 'Unknown',
            'mainAccountEmail' => $link->mainAccount?->email ?? '',
            'mainAccountRole' => $link->mainAccount?->role ?? null,
            'mainAccountAvatar' => $link->mainAccount?->avatar ?? null,
            'mainAccountStatus' => $link->mainAccount?->account_status ?? null,
            'sharedDetails' => $link->getFormattedSharedDetails(),
            'linkedAt' => $link->linked_at?->toISOString(),
            'unlinkedAt' => $link->unlinked_at?->toISOString(),
            'status' => $link->status,
            'notes' => $link->notes,
        ];
    }

    private function serializeAccountOption(User $user, array $extra = []): array
    {
        return array_merge([
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'avatar' => $user->avatar,
            'accountStatus' => $user->account_status ?? 'active',
            'company' => $user->company_name,
        ], $extra);
    }

    private function serializeCounterpartyForUser(AccountLink $link, User $user): ?array
    {
        $counterparty = (int) $link->main_account_id === (int) $user->id
            ? $link->linkedAccount
            : $link->mainAccount;

        if (!$counterparty) {
            return null;
        }

        return [
            'id' => (string) $counterparty->id,
            'name' => $counterparty->name,
            'email' => $counterparty->email,
            'role' => $counterparty->role,
            'avatar' => $counterparty->avatar,
            'status' => $link->status,
            'sharedDetails' => $link->getFormattedSharedDetails(),
        ];
    }

    private function serializeOwnerSummary(?User $owner): ?array
    {
        if (!$owner) {
            return null;
        }

        return [
            'id' => (string) $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'role' => $owner->role,
            'avatar' => $owner->avatar,
            'accountStatus' => $owner->account_status ?? 'active',
        ];
    }

    private function serializeIncomingOwnerForUser(AccountLink $link): ?array
    {
        if (!$link->mainAccount) {
            return null;
        }

        return array_merge(
            $this->serializeOwnerSummary($link->mainAccount) ?? [],
            [
                'status' => $link->status,
                'sharedDetails' => $link->getFormattedSharedDetails(),
                'linkedAt' => $link->linked_at?->toISOString(),
                'linkId' => (string) $link->id,
                'linkDirection' => 'incoming',
            ],
        );
    }

    private function buildOwnerScopedSharedData(AccountLink $link): array
    {
        $ownerId = $link->main_account_id;
        $sharedData = [
            'totalShoots' => 0,
            'totalSpent' => 0,
            'properties' => [],
            'paymentHistory' => [],
            'lastActivity' => null,
            'sharedShoots' => [],
        ];

        $lastActivityCandidates = [];

        if ($link->sharesDetail('shoots')) {
            $shoots = Shoot::query()
                ->where('client_id', $ownerId)
                ->orderByDesc('scheduled_date')
                ->orderByDesc('updated_at')
                ->get(['id', 'address', 'city', 'state', 'scheduled_date', 'status', 'workflow_status', 'hero_image', 'updated_at']);

            $sharedData['totalShoots'] = $shoots->count();
            $sharedData['sharedShoots'] = $shoots
                ->take(8)
                ->map(function (Shoot $shoot) {
                    return [
                        'id' => (string) $shoot->id,
                        'address' => $shoot->address,
                        'city' => $shoot->city,
                        'state' => $shoot->state,
                        'scheduledDate' => optional($shoot->scheduled_date)->toDateString(),
                        'status' => $shoot->workflow_status ?: $shoot->status,
                        'heroImage' => $shoot->hero_image,
                    ];
                })
                ->values()
                ->all();
            $sharedData['properties'] = $shoots
                ->groupBy(fn (Shoot $shoot) => strtolower(trim(($shoot->address ?? '') . '|' . ($shoot->city ?? '') . '|' . ($shoot->state ?? ''))))
                ->map(function (Collection $group) {
                    /** @var Shoot $first */
                    $first = $group->first();

                    return [
                        'id' => null,
                        'address' => $first->address ?? '',
                        'city' => $first->city ?? '',
                        'state' => $first->state ?? '',
                        'shootCount' => $group->count(),
                    ];
                })
                ->values()
                ->all();

            $shootLastActivity = $shoots->sortByDesc('updated_at')->first()?->updated_at?->toISOString();
            if ($shootLastActivity) {
                $lastActivityCandidates[] = $shootLastActivity;
            }
        }

        if ($link->sharesDetail('invoices')) {
            $sharedData['totalSpent'] = (float) (Shoot::query()
                ->where('client_id', $ownerId)
                ->sum('total_quote') ?? 0);

            $payments = Payment::query()
                ->whereHas('shoot', function ($query) use ($ownerId) {
                    $query->where('client_id', $ownerId);
                })
                ->with('shoot')
                ->orderByDesc('created_at')
                ->take(10)
                ->get();

            $sharedData['paymentHistory'] = $payments
                ->map(function (Payment $payment) {
                    return [
                        'id' => (string) $payment->id,
                        'amount' => (float) $payment->amount,
                        'status' => $payment->status,
                        'created_at' => $payment->created_at?->toISOString(),
                        'shoot' => $payment->shoot ? [
                            'id' => (string) $payment->shoot->id,
                            'address' => $payment->shoot->address,
                        ] : null,
                    ];
                })
                ->values()
                ->all();

            $paymentLastActivity = $payments->first()?->created_at?->toISOString();
            if ($paymentLastActivity) {
                $lastActivityCandidates[] = $paymentLastActivity;
            }
        }

        if (!empty($lastActivityCandidates)) {
            rsort($lastActivityCandidates);
            $sharedData['lastActivity'] = $lastActivityCandidates[0];
        }

        return $sharedData;
    }

    private function buildBatchMessage(array $created, array $reactivated, array $skipped, array $errors): string
    {
        $parts = [];

        if (count($created) > 0) {
            $parts[] = count($created) . ' created';
        }

        if (count($reactivated) > 0) {
            $parts[] = count($reactivated) . ' reactivated';
        }

        if (count($skipped) > 0) {
            $parts[] = count($skipped) . ' already active';
        }

        if (count($errors) > 0) {
            $parts[] = count($errors) . ' failed';
        }

        if ($parts === []) {
            return 'No account links were changed.';
        }

        return 'Batch linking complete: ' . implode(', ', $parts) . '.';
    }

    private function getLegacyAvailableAccountsResponse(Request $request, string $role): JsonResponse
    {
        $viewer = $request->user();
        $salesRepScope = $this->isSalesRepUser($viewer) ? $this->getSalesRepShootScope($viewer) : null;
        $excludeId = $request->string('excludeId')->toString();
        $ownerId = $request->string('ownerId')->toString();

        $query = User::query();

        if ($role === 'main') {
            $query->whereIn('role', $viewer && $this->isSalesRepUser($viewer) ? ['client'] : self::OWNER_ROLES);
        } elseif ($role === 'client') {
            $query->where('role', 'client')
                ->when($ownerId !== '', function ($builder) use ($ownerId) {
                    $builder->whereNotIn('id', AccountLink::query()
                        ->where('main_account_id', $ownerId)
                        ->where('status', 'active')
                        ->pluck('linked_account_id'));
                });
        }

        if ($excludeId !== '') {
            $query->where('id', '!=', $excludeId);
        }

        return response()->json([
            'success' => true,
            'accounts' => $query
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'avatar', 'account_status'])
                ->when(
                    $viewer && $this->isSalesRepUser($viewer),
                    fn (Collection $collection) => $collection->filter(
                        fn (User $user) => $this->salesRepOwnsClient($viewer, $user, $salesRepScope)
                    )->values(),
                )
                ->map(fn (User $user) => $this->serializeAccountOption($user))
                ->values(),
        ]);
    }
}
