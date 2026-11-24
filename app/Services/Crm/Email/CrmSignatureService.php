<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmSignature;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmNoticeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One signature per user, built from a form. Any change goes back to Pending;
 * the last approved version stays in use until the super admin approves the new one.
 */
class CrmSignatureService
{
    public function __construct(private readonly CrmAccess $access, private readonly CrmNoticeService $notices) {}

    public function currentApproved(int $userId): ?CrmSignature
    {
        return CrmSignature::query()->where('user_id', $userId)->where('is_current_approved', true)->first();
    }

    public function latest(int $userId): ?CrmSignature
    {
        return CrmSignature::query()->where('user_id', $userId)->orderByDesc('version')->first();
    }

    /** @param  array{name: string, title?: ?string, phone?: ?string, sender_email?: ?string, headshot_path?: ?string}  $fields */
    public function submit(User $user, array $fields): CrmSignature
    {
        $sender = strtolower(trim((string) ($fields['sender_email'] ?? $user->email)));
        $domain = strtolower((string) config('crm.email.sender_domain', 'reprophotos.com'));
        if (! str_ends_with($sender, '@'.$domain)) {
            throw ValidationException::withMessages(['sender_email' => 'Emails are sent from your own @'.$domain.' address.']);
        }

        $latest = $this->latest($user->id);
        $clean = [
            'name' => trim($fields['name']),
            'title' => trim((string) ($fields['title'] ?? '')),
            'phone' => trim((string) ($fields['phone'] ?? '')),
        ];
        $headshot = array_key_exists('headshot_path', $fields) ? $fields['headshot_path'] : $latest?->headshot_path;

        if ($latest && $latest->fields == $clean && $latest->sender_email === $sender && $latest->headshot_path === $headshot && $latest->status !== 'rejected') {
            return $latest;
        }

        $signature = CrmSignature::create([
            'user_id' => $user->id,
            'version' => ($latest?->version ?? 0) + 1,
            'fields' => $clean,
            'headshot_path' => $headshot,
            'sender_email' => $sender,
            'status' => 'pending',
        ]);

        // Older pending versions are superseded by this one.
        CrmSignature::query()->where('user_id', $user->id)->where('status', 'pending')->whereKeyNot($signature->id)->update(['status' => 'superseded']);
        $this->notifySuperAdmins($user);

        return $signature;
    }

    public function approve(CrmSignature $signature, User $reviewer): CrmSignature
    {
        $this->assertReviewer($reviewer);
        if ($signature->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only pending signatures can be approved.']);
        }

        DB::transaction(function () use ($signature, $reviewer) {
            CrmSignature::query()->where('user_id', $signature->user_id)->update(['is_current_approved' => false]);
            $signature->forceFill(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'is_current_approved' => true, 'review_comment' => null])->save();
        });
        $this->notices->notify($signature->user_id, 'signature_approved', 'Your email signature was approved. You can send email from the CRM.');

        return $signature;
    }

    public function reject(CrmSignature $signature, User $reviewer, string $comment): CrmSignature
    {
        $this->assertReviewer($reviewer);
        if ($signature->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only pending signatures can be sent back.']);
        }
        $signature->forceFill(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_comment' => $comment])->save();
        $this->notices->notify($signature->user_id, 'signature_rejected', 'Your email signature needs a change', $comment);

        return $signature;
    }

    /** Why this user cannot send email, or null if they can. */
    public function sendBlocker(int $userId): ?string
    {
        if ($this->currentApproved($userId)) {
            return null;
        }
        $latest = $this->latest($userId);

        return match ($latest?->status) {
            'pending' => 'Your signature is waiting for approval, so you can’t send email yet.',
            'rejected' => 'Your signature was sent back with a comment. Update it to send email.',
            default => 'Set up your email signature first. The super admin approves it before you can send email.',
        };
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $this->access->can($reviewer, 'crm-signatures', 'approve')) {
            throw new AuthorizationException('Only the super admin approves email signatures.');
        }
    }

    private function notifySuperAdmins(User $user): void
    {
        User::query()->where('role', 'superadmin')->pluck('id')->each(fn ($id) => $id !== $user->id
            ? $this->notices->notify((int) $id, 'signature_pending', $user->name.' submitted an email signature for approval')
            : null);
    }
}
