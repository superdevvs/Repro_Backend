<?php

namespace App\Http\Controllers\API\Crm;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmDuplicateException;
use App\Services\Crm\CrmPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shared helpers for CRM controllers. */
abstract class CrmController extends Controller
{
    protected function access(): CrmAccess
    {
        return app(CrmAccess::class);
    }

    protected function presenter(): CrmPresenter
    {
        return app(CrmPresenter::class);
    }

    protected function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function requirePermission(Request $request, string $resource, string $action): ?JsonResponse
    {
        if ($this->access()->can($request->user(), $resource, $action)) {
            return null;
        }

        return response()->json(['message' => 'You do not have permission to do this.', 'resource' => $resource, 'action' => $action], 403);
    }

    /**
     * A rep who hits a duplicate is told it exists and that an admin was
     * notified, and nothing about whose contact it is. Admins get the match.
     */
    protected function duplicateResponse(CrmDuplicateException $e, ?User $viewer): JsonResponse
    {
        $match = $e->match;
        $payload = [
            'message' => $match->type === 'name'
                ? 'A contact with this name is already in the CRM.'
                : 'This contact is already in the CRM. You can’t add them again.',
            'duplicate' => [
                'blocked' => true,
                'reason' => $match->type,
                'admin_notified' => $e->adminNotified,
                'overridable' => false,
            ],
        ];

        if ($this->access()->canViewAll($viewer)) {
            $payload['duplicate']['overridable'] = $match->isOverridable();
            $payload['duplicate']['match'] = $this->presenter()->contact($match->contact, $viewer);
            if ($match->isOverridable()) {
                $payload['message'] = 'A contact with this name already exists. Confirm it is a different person to save.';
            }
        } elseif ($e->adminNotified) {
            $payload['message'] = 'This contact is already in the CRM. You can’t add them again. An admin has been notified and will follow up with you.';
        }

        return response()->json($payload, 409);
    }
}
