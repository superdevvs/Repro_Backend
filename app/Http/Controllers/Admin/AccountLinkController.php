<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\AccountLink;
use App\Models\User;
use App\Models\Shoot;
use App\Models\Payment;
use Carbon\Carbon;

class AccountLinkController extends Controller
{
    /**
     * Get all account links with formatted data for frontend
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Check user role - allow editing_manager as well
            $user = $request->user();
            if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access restricted to specific roles'
                ], 403);
            }

            // Load relationships and filter active links
            $links = AccountLink::with(['mainAccount', 'linkedAccount'])
                ->where('status', 'active')
                ->get()
                ->map(function ($link) {
                    return [
                        'id' => (string) $link->id,
                        'accountId' => (string) $link->linked_account_id,
                        'accountName' => $link->linkedAccount->name ?? 'Unknown',
                        'accountEmail' => $link->linkedAccount->email ?? '',
                        'accountAvatar' => $link->linkedAccount->avatar ?? null,
                        'mainAccountId' => (string) $link->main_account_id,
                        'mainAccountName' => $link->mainAccount->name ?? 'Unknown',
                        'mainAccountEmail' => $link->mainAccount->email ?? '',
                        'mainAccountAvatar' => $link->mainAccount->avatar ?? null,
                        'sharedDetails' => $link->getFormattedSharedDetails(),
                        'linkedAt' => $link->linked_at?->toISOString(),
                        'status' => $link->status,
                        'notes' => $link->notes,
                    ];
                });
            
            return response()->json([
                'success' => true,
                'links' => $links,
                'total' => $links->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch account links: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Create a new account link
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'mainAccountId' => 'required|exists:users,id',
                'clientAccountId' => 'required|exists:users,id|different:mainAccountId',
                'sharedDetails' => 'required|array',
                'notes' => 'nullable|string|max:500',
            ]);

            // Check if link already exists
            $existingLink = AccountLink::where([
                'main_account_id' => $validated['mainAccountId'],
                'linked_account_id' => $validated['clientAccountId'],
                'status' => 'active'
            ])->first();

            if ($existingLink) {
                return response()->json([
                    'success' => false,
                    'message' => 'These accounts are already linked.',
                ], 422);
            }

            $link = AccountLink::create([
                'main_account_id' => $validated['mainAccountId'],
                'linked_account_id' => $validated['clientAccountId'],
                'shared_details' => $validated['sharedDetails'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'active',
                'linked_at' => now(),
                'created_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Accounts linked successfully!',
                'link' => [
                    'id' => $link->id,
                    'mainAccountId' => $link->main_account_id,
                    'mainAccountName' => $link->mainAccount->name,
                    'accountId' => $link->linked_account_id,
                    'accountName' => $link->linkedAccount->name,
                    'accountEmail' => $link->linkedAccount->email,
                    'sharedDetails' => $link->getFormattedSharedDetails(),
                    'linkedAt' => $link->linked_at->toISOString(),
                    'notes' => $link->notes,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to link accounts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create multiple account links at once
     */
    public function batchStore(Request $request): JsonResponse
    {
        try {
            // Check user role
            $user = $request->user();
            if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access restricted to specific roles'
                ], 403);
            }

            $validated = $request->validate([
                'mainAccountId' => 'required|exists:users,id',
                'clientAccountIds' => 'required|array|min:1',
                'clientAccountIds.*' => 'exists:users,id|different:mainAccountId',
                'sharedDetails' => 'required|array',
                'notes' => 'nullable|string|max:500',
            ]);

            $mainAccountId = $validated['mainAccountId'];
            $clientAccountIds = $validated['clientAccountIds'];
            $sharedDetails = $validated['sharedDetails'];
            $notes = $validated['notes'] ?? null;

            $createdLinks = [];
            $skippedLinks = [];
            $errors = [];

            foreach ($clientAccountIds as $clientId) {
                try {
                    // Check if link already exists
                    $existingLink = AccountLink::where([
                        'main_account_id' => $mainAccountId,
                        'linked_account_id' => $clientId,
                        'status' => 'active'
                    ])->first();

                    if ($existingLink) {
                        $skippedLinks[] = [
                            'accountId' => $clientId,
                            'reason' => 'Already linked'
                        ];
                        continue;
                    }

                    $link = AccountLink::create([
                        'main_account_id' => $mainAccountId,
                        'linked_account_id' => $clientId,
                        'shared_details' => $sharedDetails,
                        'notes' => $notes,
                        'status' => 'active',
                        'linked_at' => now(),
                        'created_by' => auth()->id(),
                    ]);

                    $createdLinks[] = [
                        'id' => $link->id,
                        'mainAccountId' => $link->main_account_id,
                        'mainAccountName' => $link->mainAccount->name,
                        'accountId' => $link->linked_account_id,
                        'accountName' => $link->linkedAccount->name,
                        'accountEmail' => $link->linkedAccount->email,
                        'sharedDetails' => $link->getFormattedSharedDetails(),
                        'linkedAt' => $link->linked_at->toISOString(),
                    ];

                } catch (\Exception $e) {
                    $errors[] = [
                        'accountId' => $clientId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $message = "Batch linking completed. ";
            if (count($createdLinks) > 0) {
                $message .= count($createdLinks) . " account(s) linked successfully. ";
            }
            if (count($skippedLinks) > 0) {
                $message .= count($skippedLinks) . " account(s) skipped. ";
            }
            if (count($errors) > 0) {
                $message .= count($errors) . " account(s) failed.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'created' => $createdLinks,
                'skipped' => $skippedLinks,
                'errors' => $errors,
                'summary' => [
                    'total' => count($clientAccountIds),
                    'created' => count($createdLinks),
                    'skipped' => count($skippedLinks),
                    'failed' => count($errors),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Batch linking failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update shared details for an existing link
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $link = AccountLink::findOrFail($id);

            $validated = $request->validate([
                'sharedDetails' => 'required|array',
                'notes' => 'nullable|string|max:500',
                'status' => 'sometimes|in:active,inactive,suspended',
            ]);

            // Validate shared details format
            $allowedDetails = ['shoots', 'invoices', 'clients', 'availability', 'settings', 'profile', 'documents'];
            $sharedDetails = array_intersect_key($validated['sharedDetails'], array_flip($allowedDetails));

            $updateData = [
                'shared_details' => $sharedDetails,
            ];

            if (isset($validated['notes'])) {
                $updateData['notes'] = $validated['notes'];
            }

            if (isset($validated['status'])) {
                $updateData['status'] = $validated['status'];
                if ($validated['status'] === 'inactive' || $validated['status'] === 'suspended') {
                    $updateData['unlinked_at'] = now();
                } else {
                    $updateData['unlinked_at'] = null;
                }
            }

            $link->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Account link updated successfully!',
                'link' => [
                    'id' => $link->id,
                    'sharedDetails' => $link->getFormattedSharedDetails(),
                    'status' => $link->status,
                    'notes' => $link->notes,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Account link not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update account link: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete/unlink an account connection
     */
    public function destroy($id): JsonResponse
    {
        try {
            $link = AccountLink::findOrFail($id);

            // Soft delete by marking as inactive
            $link->update([
                'status' => 'inactive',
                'unlinked_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Account unlinked successfully!',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Account link not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unlink account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get shared data for a specific account (for viewing linked account details)
     */
    public function getSharedData(Request $request, $accountId): JsonResponse
    {
        try {
            $user = User::findOrFail($accountId);
            
            // Get all linked account IDs
            $linkedAccountIds = AccountLink::getLinkedAccountIds($accountId);
            $allAccountIds = array_merge([$accountId], $linkedAccountIds);

            // Get shared data based on link permissions
            $sharedData = [
                'linkedAccounts' => [],
                'totalShoots' => 0,
                'totalSpent' => 0,
                'properties' => [],
                'paymentHistory' => [],
                'lastActivity' => null,
            ];

            // Get linked accounts info
            $links = AccountLink::forAccount($accountId)
                ->active()
                ->with(['mainAccount', 'linkedAccount'])
                ->get();

            foreach ($links as $link) {
                $linkedUser = $link->main_account_id === $accountId 
                    ? $link->linkedAccount 
                    : $link->mainAccount;

                $sharedData['linkedAccounts'][] = [
                    'id' => $linkedUser->id,
                    'name' => $linkedUser->name,
                    'email' => $linkedUser->email,
                    'role' => $linkedUser->role,
                    'account_status' => $linkedUser->account_status ?? 'active',
                    'sharedDetails' => $link->getFormattedSharedDetails(),
                    'linkedAt' => $link->linked_at->toISOString(),
                ];
            }

            // Aggregate shared data based on permissions
            $canSeeShoots = $links->contains(function($link) use ($accountId) {
                return $link->sharesDetail('shoots');
            });

            $canSeeInvoices = $links->contains(function($link) use ($accountId) {
                return $link->sharesDetail('invoices');
            });

            if ($canSeeShoots) {
                $sharedData['totalShoots'] = Shoot::whereIn('client_id', $allAccountIds)->count();
                
                // Get properties
                $properties = Shoot::whereIn('client_id', $allAccountIds)
                    ->with('location')
                    ->get()
                    ->groupBy('location_id')
                    ->map(function($shoots) {
                        $first = $shoots->first();
                        return [
                            'id' => $first->location->id ?? null,
                            'address' => $first->location->fullAddress ?? 'N/A',
                            'city' => $first->location->city ?? 'N/A',
                            'state' => $first->location->state ?? 'N/A',
                            'shootCount' => $shoots->count(),
                        ];
                    })
                    ->values()
                    ->toArray();

                $sharedData['properties'] = $properties;
                $sharedData['lastActivity'] = Shoot::whereIn('client_id', $allAccountIds)
                    ->orderBy('updated_at', 'desc')
                    ->first()?->updated_at?->toISOString();
            }

            if ($canSeeInvoices) {
                $sharedData['totalSpent'] = Shoot::whereIn('client_id', $allAccountIds)
                    ->sum('total_quote') ?? 0;

                // Get payment history
                $sharedData['paymentHistory'] = Payment::whereIn('user_id', $allAccountIds)
                    ->with('shoot')
                    ->orderBy('created_at', 'desc')
                    ->take(10)
                    ->get()
                    ->map(function($payment) {
                        return [
                            'id' => $payment->id,
                            'amount' => $payment->amount,
                            'status' => $payment->status,
                            'created_at' => $payment->created_at->toISOString(),
                            'shoot' => $payment->shoot ? [
                                'id' => $payment->shoot->id,
                                'address' => $payment->shoot->location?->fullAddress ?? 'N/A',
                            ] : null,
                        ];
                    })
                    ->toArray();
            }

            return response()->json([
                'success' => true,
                'sharedData' => $sharedData,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get shared data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available accounts for linking (filtered by role and existing links)
     */
    public function getAvailableAccounts(Request $request): JsonResponse
    {
        try {
            $role = $request->get('role'); // 'main' or 'client'
            $excludeId = $request->get('excludeId'); // Exclude this account from results

            $query = User::query();

            if ($role === 'main') {
                $query->whereIn('role', ['admin', 'superadmin', 'client']);
            } elseif ($role === 'client') {
                $query->where('role', 'client');
            }

            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }

            $accounts = $query->select(['id', 'name', 'email', 'role'])
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'accounts' => $accounts,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get available accounts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if current user has any linked accounts (for showing/hiding linked tab)
     */
    public function hasLinkedAccounts(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'hasLinkedAccounts' => false,
                ], 401);
            }

            // Check if user is a main account with linked accounts
            $asMainAccount = AccountLink::where('main_account_id', $user->id)
                ->where('status', 'active')
                ->exists();

            // Check if user is a linked account
            $asLinkedAccount = AccountLink::where('linked_account_id', $user->id)
                ->where('status', 'active')
                ->exists();

            $hasLinks = $asMainAccount || $asLinkedAccount;

            // If has links, also return basic info about linked accounts
            $linkedAccounts = [];
            if ($hasLinks) {
                // Get accounts linked TO this user (user is main)
                $linkedTo = AccountLink::with('linkedAccount')
                    ->where('main_account_id', $user->id)
                    ->where('status', 'active')
                    ->get();

                foreach ($linkedTo as $link) {
                    $linkedUser = $link->linkedAccount;
                    if ($linkedUser) {
                        $linkedAccounts[] = [
                            'id' => (string) $linkedUser->id,
                            'name' => $linkedUser->name,
                            'email' => $linkedUser->email,
                            'role' => $linkedUser->role,
                            'avatar' => $linkedUser->avatar,
                            'sharedDetails' => $link->getFormattedSharedDetails(),
                        ];
                    }
                }

                // Get accounts this user is linked FROM (user is linked account)
                $linkedFrom = AccountLink::with('mainAccount')
                    ->where('linked_account_id', $user->id)
                    ->where('status', 'active')
                    ->get();

                foreach ($linkedFrom as $link) {
                    $mainUser = $link->mainAccount;
                    if ($mainUser) {
                        $linkedAccounts[] = [
                            'id' => (string) $mainUser->id,
                            'name' => $mainUser->name,
                            'email' => $mainUser->email,
                            'role' => $mainUser->role,
                            'avatar' => $mainUser->avatar,
                            'sharedDetails' => $link->getFormattedSharedDetails(),
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'hasLinkedAccounts' => $hasLinks,
                'linkedAccounts' => $linkedAccounts,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'hasLinkedAccounts' => false,
                'message' => 'Error checking linked accounts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get full linked accounts data with shared shoots for the linked tab
     */
    public function getLinkedAccountsForUser(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $linkedAccounts = [];

            // Get accounts linked TO this user (user is main account)
            $linkedTo = AccountLink::with('linkedAccount')
                ->where('main_account_id', $user->id)
                ->where('status', 'active')
                ->get();

            foreach ($linkedTo as $link) {
                $linkedUser = $link->linkedAccount;
                if (!$linkedUser) continue;

                $sharedDetails = $link->getFormattedSharedDetails();
                $sharedShoots = [];

                // If shoots are shared, get them
                if (isset($sharedDetails['shoots']) && $sharedDetails['shoots']) {
                    $sharedShoots = Shoot::where('client_id', $linkedUser->id)
                        ->select(['id', 'address', 'city', 'state', 'scheduled_date', 'status', 'hero_image'])
                        ->orderBy('scheduled_date', 'desc')
                        ->limit(10)
                        ->get()
                        ->map(function ($shoot) {
                            return [
                                'id' => $shoot->id,
                                'address' => $shoot->address,
                                'city' => $shoot->city,
                                'state' => $shoot->state,
                                'scheduledDate' => $shoot->scheduled_date,
                                'status' => $shoot->status,
                                'hero_image' => $shoot->hero_image,
                            ];
                        });
                }

                $linkedAccounts[] = [
                    'id' => (string) $linkedUser->id,
                    'name' => $linkedUser->name,
                    'email' => $linkedUser->email,
                    'role' => $linkedUser->role,
                    'avatar' => $linkedUser->avatar,
                    'sharedDetails' => $sharedDetails,
                    'sharedShoots' => $sharedShoots,
                    'linkDirection' => 'outgoing', // This user is the main account
                ];
            }

            // Get accounts this user is linked FROM (user is linked account)
            $linkedFrom = AccountLink::with('mainAccount')
                ->where('linked_account_id', $user->id)
                ->where('status', 'active')
                ->get();

            foreach ($linkedFrom as $link) {
                $mainUser = $link->mainAccount;
                if (!$mainUser) continue;

                $sharedDetails = $link->getFormattedSharedDetails();
                $sharedShoots = [];

                // If shoots are shared, get the main account's shoots
                if (isset($sharedDetails['shoots']) && $sharedDetails['shoots']) {
                    $sharedShoots = Shoot::where('client_id', $mainUser->id)
                        ->select(['id', 'address', 'city', 'state', 'scheduled_date', 'status', 'hero_image'])
                        ->orderBy('scheduled_date', 'desc')
                        ->limit(10)
                        ->get()
                        ->map(function ($shoot) {
                            return [
                                'id' => $shoot->id,
                                'address' => $shoot->address,
                                'city' => $shoot->city,
                                'state' => $shoot->state,
                                'scheduledDate' => $shoot->scheduled_date,
                                'status' => $shoot->status,
                                'hero_image' => $shoot->hero_image,
                            ];
                        });
                }

                $linkedAccounts[] = [
                    'id' => (string) $mainUser->id,
                    'name' => $mainUser->name,
                    'email' => $mainUser->email,
                    'role' => $mainUser->role,
                    'avatar' => $mainUser->avatar,
                    'sharedDetails' => $sharedDetails,
                    'sharedShoots' => $sharedShoots,
                    'linkDirection' => 'incoming', // This user is the linked account
                ];
            }

            return response()->json([
                'success' => true,
                'linkedAccounts' => $linkedAccounts,
                'total' => count($linkedAccounts),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get linked accounts: ' . $e->getMessage(),
            ], 500);
        }
    }
}
