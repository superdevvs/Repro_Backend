<?php

namespace App\Services\Crm\Robbie;

use App\Models\Crm\CrmAiProposal;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDripCampaign;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmActivityLogger;
use App\Services\Crm\CrmEventService;
use App\Services\Crm\CrmNoteService;
use App\Services\Crm\CrmStageService;
use App\Services\Crm\Email\CrmContentCheck;
use App\Services\Crm\Email\CrmDripService;
use App\Services\Crm\Email\CrmEmailSender;
use App\Services\Crm\Email\CrmHtmlSanitizer;
use App\Services\Crm\Email\CrmSendBlocked;
use Illuminate\Validation\ValidationException;

/**
 * Propose, then confirm. Robbie never writes: it freezes what it would do as a
 * proposal owned by the signed-in user. Only that user can confirm it (with
 * edits), once, before it expires, and it then runs through the ordinary CRM
 * services with the same permission checks, logged "via Robbie".
 */
class CrmProposalService
{
    public function __construct(private readonly CrmAccess $access) {}

    public function propose(User $user, string $kind, ?CrmContact $contact, array $payload, string $summary): CrmAiProposal
    {
        if (! in_array($kind, CrmAiProposal::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown action.']);
        }
        if ($contact && ! CrmContact::query()->whereKey($contact->id)->exists()) {
            throw ValidationException::withMessages(['contact_id' => 'Contact not found.']);
        }
        $this->validatePayload($kind, $payload, $user);

        return CrmAiProposal::create([
            'user_id' => $user->id,
            'contact_id' => $contact?->id,
            'kind' => $kind,
            'summary' => mb_substr($summary, 0, 255),
            'payload' => $payload,
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
    }

    /** @param  array<string, mixed>  $edits  fields the user changed before confirming */
    public function confirm(CrmAiProposal $proposal, User $user, array $edits = []): CrmAiProposal
    {
        abort_unless((int) $proposal->user_id === (int) $user->id, 404);
        if ($proposal->status !== 'pending') {
            throw ValidationException::withMessages(['proposal' => 'This suggestion was already '.$proposal->status.'.']);
        }
        if ($proposal->expires_at->isPast()) {
            $proposal->forceFill(['status' => 'expired', 'decided_at' => now()])->save();
            throw ValidationException::withMessages(['proposal' => 'This suggestion has expired. Ask Robbie again.']);
        }
        // Claim it first so a double click cannot run it twice.
        $claimed = CrmAiProposal::query()->whereKey($proposal->id)->where('status', 'pending')->update(['status' => 'running', 'updated_at' => now()]);
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['proposal' => 'This suggestion is already being handled.']);
        }

        $payload = array_merge($proposal->payload ?? [], collect($edits)->only($this->editable($proposal->kind))->all());
        try {
            $this->validatePayload($proposal->kind, $payload, $user);
            $contact = $proposal->contact_id ? CrmContact::query()->findOrFail($proposal->contact_id) : null;
            $result = CrmActivityLogger::asRobbie(fn () => $this->execute($proposal->kind, $contact, $payload, $user, $proposal->id));
            $proposal->forceFill(['status' => 'confirmed', 'payload' => $payload, 'result' => $result, 'decided_at' => now()])->save();
        } catch (\Throwable $e) {
            // Release the claim so the user can fix the problem and confirm again.
            CrmAiProposal::query()->whereKey($proposal->id)->update(['status' => 'pending', 'updated_at' => now()]);
            $proposal->status = 'pending';
            throw $e;
        }

        return $proposal;
    }

    public function dismiss(CrmAiProposal $proposal, User $user): CrmAiProposal
    {
        abort_unless((int) $proposal->user_id === (int) $user->id, 404);
        if ($proposal->status === 'pending') {
            $proposal->forceFill(['status' => 'dismissed', 'decided_at' => now()])->save();
        }

        return $proposal;
    }

    public function present(CrmAiProposal $p): array
    {
        $contact = $p->contact_id ? CrmContact::query()->find($p->contact_id) : null;

        return [
            'id' => $p->id, 'kind' => $p->kind, 'summary' => $p->summary, 'payload' => $p->payload, 'status' => $p->status,
            'contact' => $contact ? ['id' => $contact->id, 'name' => $contact->name, 'company' => $contact->company] : null,
            'editable' => $this->editable($p->kind), 'result' => $p->result, 'expires_at' => $p->expires_at?->toIso8601String(),
        ];
    }

    private function editable(string $kind): array
    {
        return match ($kind) {
            'note' => ['body'],
            'event' => ['type', 'starts_at', 'duration_min', 'note'],
            'stage' => ['stage', 'lost_reason', 'lost_note'],
            'email' => ['subject', 'body_html'],
            'drip' => ['campaign_id'],
            default => [],
        };
    }

    private function validatePayload(string $kind, array $p, User $user): void
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['payload' => $msg]);
        $need = fn (string $resource, string $action) => $this->access->can($user, $resource, $action) ?: $fail('You don’t have permission to do this.');
        match ($kind) {
            'note' => trim((string) ($p['body'] ?? '')) !== '' && mb_strlen((string) $p['body']) <= 10000 ? $need('crm-contacts', 'update') : $fail('The note is empty.'),
            'event' => ! empty($p['starts_at']) && strtotime((string) $p['starts_at']) ? $need('crm-contacts', 'update') : $fail('Pick a date and time.'),
            'stage' => in_array($p['stage'] ?? null, [...CrmContact::PIPELINE_STAGES, CrmContact::STAGE_LOST], true)
                && (($p['stage'] ?? null) !== CrmContact::STAGE_LOST || in_array($p['lost_reason'] ?? null, CrmContact::LOST_REASONS, true))
                ? $need('crm-contacts', 'update') : $fail('Choose a stage (and a reason for Lost).'),
            'email' => CrmEmailTemplate::query()->whereKey((int) ($p['template_id'] ?? 0))->whereNotNull('approved_version_id')->where('status', '!=', 'retired')->exists()
                ? $need('crm-contacts', 'update') : $fail('Emails must start from an approved template.'),
            'drip' => CrmDripCampaign::query()->whereKey((int) ($p['campaign_id'] ?? 0))->where('status', 'live')->exists()
                ? $need('crm-drips', 'enrol') : $fail('Pick a live drip.'),
            default => $fail('Unknown action.'),
        };
    }

    private function execute(string $kind, ?CrmContact $contact, array $p, User $user, int $proposalId): array
    {
        if (! $contact) {
            throw ValidationException::withMessages(['contact_id' => 'Contact not found.']);
        }

        switch ($kind) {
            case 'note':
                $note = app(CrmNoteService::class)->add($contact, (string) $p['body'], $user, true);

                return ['type' => 'note', 'id' => $note->id];
            case 'event':
                $event = app(CrmEventService::class)->schedule($contact, collect($p)->only(['type', 'type_id', 'starts_at', 'duration_min', 'note'])->all() + ['assigned_user_id' => $contact->assigned_rep_id ?? $user->id], $user, true);

                return ['type' => 'event', 'id' => $event->id];
            case 'stage':
                $stages = app(CrmStageService::class);
                $p['stage'] === CrmContact::STAGE_LOST
                    ? $stages->markLost($contact, (string) $p['lost_reason'], $p['lost_note'] ?? null, $user)
                    : $stages->move($contact, (string) $p['stage'], $user);

                return ['type' => 'stage', 'stage' => $p['stage']];
            case 'drip':
                $enrollment = app(CrmDripService::class)->enrol($contact, CrmDripCampaign::query()->findOrFail((int) $p['campaign_id']), $user, true);

                return ['type' => 'drip', 'id' => $enrollment->id];
            case 'email':
                return $this->sendEmail($contact, $p, $user, $proposalId);
        }

        throw ValidationException::withMessages(['kind' => 'Unknown action.']);
    }

    /** Same rules as a one-off email: approved template, edited wording must pass the content check. */
    private function sendEmail(CrmContact $contact, array $p, User $user, int $proposalId): array
    {
        $template = CrmEmailTemplate::query()->findOrFail((int) $p['template_id']);
        $version = $template->approvedVersion;
        $subject = trim((string) ($p['subject'] ?? '')) ?: $version->subject;
        $body = trim((string) ($p['body_html'] ?? '')) !== '' ? CrmHtmlSanitizer::clean((string) $p['body_html']) : $version->body_html;
        if ($subject !== $version->subject || trim($body) !== trim($version->body_html)) {
            $check = app(CrmContentCheck::class)->check($subject."\n".CrmHtmlSanitizer::toText($body), $user);
            if (! $check['passed']) {
                throw ValidationException::withMessages(['body_html' => 'Fix the flagged wording before sending: '.collect($check['issues'] ?? [])->pluck('message')->filter()->implode('; ')]);
            }
        }
        try {
            $send = app(CrmEmailSender::class)->send([
                'contact' => $contact, 'sender' => $user, 'source_type' => 'one_off', 'template_version' => $version,
                'content' => ['subject' => $subject, 'body_html' => $body, 'preheader' => $version->preheader, 'image_url' => $version->image_url, 'cta_label' => $version->cta_label, 'cta_url' => $version->cta_url],
                'marketing' => true, 'counts_as_contact' => true, 'idempotency_key' => 'crm-robbie-proposal-'.$proposalId,
            ]);
        } catch (CrmSendBlocked $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return ['type' => 'email', 'id' => $send->id, 'status' => $send->status];
    }
}
