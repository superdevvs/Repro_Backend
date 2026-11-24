<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmSignature;
use App\Services\Crm\Email\CrmEmailPresenter;
use App\Services\Crm\Email\CrmSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Your own signature, and (super admin) the approval queue. */
class CrmSignatureController extends CrmController
{
    public function __construct(private readonly CrmSignatureService $signatures, private readonly CrmEmailPresenter $present) {}

    public function me(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        return response()->json([
            'latest' => $this->present->signature($this->signatures->latest($user->id)),
            'approved' => $this->present->signature($this->signatures->currentApproved($user->id)),
            'send_blocker' => $this->signatures->sendBlocker($user->id),
            'defaults' => ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phonenumber ?: $user->phone],
            'sender_domain' => config('crm.email.sender_domain'),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'sender_email' => ['nullable', 'email', 'max:190'],
            'remove_headshot' => ['nullable', 'boolean'],
        ]);
        if ($request->boolean('remove_headshot')) {
            $data['headshot_path'] = null;
        }
        unset($data['remove_headshot']);
        $signature = $this->signatures->submit($this->actor($request), $data);

        return response()->json(['signature' => $this->present->signature($signature)], 201);
    }

    public function headshot(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072']]);
        $user = $this->actor($request);
        $path = $request->file('file')->store('crm/headshots/'.$user->id, 'public');
        $latest = $this->signatures->latest($user->id);
        $signature = $this->signatures->submit($user, array_merge($latest?->fields ?? ['name' => $user->name], [
            'sender_email' => $latest?->sender_email ?? $user->email,
            'headshot_path' => $path,
        ]));

        return response()->json(['signature' => $this->present->signature($signature)], 201);
    }

    public function pending(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-signatures', 'approve')) {
            return $denied;
        }
        $items = CrmSignature::query()->where('status', 'pending')->orderBy('created_at')->get();

        return response()->json(['data' => $items->map(function (CrmSignature $s) {
            $previous = CrmSignature::query()->where('user_id', $s->user_id)->where('is_current_approved', true)->first();

            return $this->present->signature($s) + ['change' => $this->describeChange($previous, $s)];
        })->all()]);
    }

    public function approve(Request $request, CrmSignature $signature): JsonResponse
    {
        $this->signatures->approve($signature, $this->actor($request));

        return response()->json(['signature' => $this->present->signature($signature->fresh())]);
    }

    public function reject(Request $request, CrmSignature $signature): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:500']]);
        $this->signatures->reject($signature, $this->actor($request), $data['comment']);

        return response()->json(['signature' => $this->present->signature($signature->fresh())]);
    }

    private function describeChange(?CrmSignature $previous, CrmSignature $current): string
    {
        if (! $previous) {
            return 'New signature';
        }
        $changes = [];
        foreach (['name' => 'name', 'title' => 'title', 'phone' => 'phone'] as $key => $label) {
            if (($previous->fields[$key] ?? '') !== ($current->fields[$key] ?? '')) {
                $changes[] = 'Changed '.$label.' from “'.($previous->fields[$key] ?? '').'”';
            }
        }
        if ($previous->headshot_path !== $current->headshot_path) {
            $changes[] = $current->headshot_path ? 'Added headshot' : 'Removed headshot';
        }
        if ($previous->sender_email !== $current->sender_email) {
            $changes[] = 'Changed sending address';
        }

        return $changes ? implode('; ', $changes) : 'Resubmitted';
    }
}
