<?php

namespace App\Services\MobileAssistant;

use App\Models\Message;
use App\Models\MobileAssistantAction;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AssistantActions
{
    public function __construct(private AssistantCapabilities $capabilities, private AssistantProposals $proposals, private AssistantEndpoint $endpoint) {}

    public function prepare(User $actor, string $requestID, string $action, array $params): array
    {
        $this->capabilities->require($actor, $action);
        $hash = hash('sha256', json_encode(Arr::sortRecursive([$action, $params])));
        $old = MobileAssistantAction::where('user_id', $actor->id)->where('request_id', $requestID)->first();
        if ($old) {
            abort_unless(hash_equals($old->request_hash, $hash), 409, 'This request ID already belongs to a different action.');

            return $this->receipt($actor, $old->id);
        }
        $proposal = $this->proposals->make($actor, $action, $params);
        $id = (string) Str::uuid();
        $row = LockedWrite::run(fn () => MobileAssistantAction::firstOrCreate(['user_id' => $actor->id, 'request_id' => $requestID], [
            'id' => $id, 'action' => $action, 'request_hash' => $hash, 'scope_hash' => $this->capabilities->scopeHash($actor),
            'target_version' => $proposal['target_version'], 'proposal' => $proposal,
            'expires_at' => now()->addSeconds((int) config('mobile-assistant.proposal_seconds', 300)),
        ]), 'assistant.prepare');
        abort_unless(hash_equals($row->request_hash, $hash), 409, 'This request ID already belongs to a different action.');

        return $this->payload($row);
    }

    private function owned(User $actor, string $id): MobileAssistantAction
    {
        $this->capabilities->ensureEnabled($actor);

        return MobileAssistantAction::where('user_id', $actor->id)->findOrFail($id);
    }

    private function validateCurrent(User $actor, MobileAssistantAction $row): array
    {
        $this->capabilities->require($actor, $row->action);
        abort_unless(hash_equals($row->scope_hash, $this->capabilities->scopeHash($actor)), 409, 'Your permissions changed. Prepare this action again.');
        $fresh = $this->proposals->make($actor, $row->action, $row->proposal['params']);
        abort_unless($fresh['target_version'] === $row->target_version
            && $fresh['params'] === $row->proposal['params'] && $fresh['mode'] === $row->proposal['mode'], 409,
            'The record or selected recipients changed. Review and confirm again.');

        return $fresh;
    }

    public function confirm(User $actor, string $id): array
    {
        $row = $this->owned($actor, $id);
        if ($row->state !== 'prepared') {
            return $this->receipt($actor, $id);
        }
        abort_if($row->expires_at->isPast(), 409, 'This proposal expired. Prepare and confirm it again.');
        $proposal = $this->validateCurrent($actor, $row);
        $claimed = LockedWrite::run(fn () => MobileAssistantAction::whereKey($id)->where('state', 'prepared')
            ->where('expires_at', '>', now())->update(['state' => 'executing', 'started_at' => now(), 'updated_at' => now()]), 'assistant.claim');
        if (! $claimed) {
            return $this->receipt($actor, $id);
        }
        $row->refresh();
        if (in_array($proposal['mode'], ['review', 'handoff'], true)) {
            $row->forceFill(['state' => 'handoff', 'result' => ['spoken' => 'Continue in R/E Pro.', 'route' => $proposal['route'],
                'record_id' => $proposal['record_id'], 'draft' => $proposal['params']], 'completed_at' => now()])->save();

            return $this->payload($row);
        }
        try {
            $execute = function () use ($actor, $row, $proposal) {
                // Local-only writes and their receipts commit together. A process exit cannot duplicate them.
                if (in_array($row->action, ['note.create', 'availability.create'], true)) {
                    $this->validateCurrent($actor->fresh(), $row);
                }
                $body = $proposal['params'];
                unset($body['shoot_id'], $body['thread_id'], $body['timezone'], $body['starts_at'], $body['ends_at']);
                if ($row->action === 'support.create') {
                    $body['request_key'] = $row->request_id;
                }
                $response = $this->endpoint->invoke($actor, $proposal['method'], $proposal['path'], $body, $row->id);
                $result = $this->outcome($row, $response);
                $row->forceFill(['state' => $result['state'], 'result' => $result, 'completed_at' => now()])->save();
            };
            if (in_array($row->action, ['note.create', 'availability.create'], true)) {
                LockedWrite::run(fn () => DB::transaction($execute), 'assistant.local_action');
            } else {
                // Never hold SQLite's writer lock while a provider, notification or travel service runs.
                $execute();
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            $row->forceFill(['state' => 'failed', 'result' => ['spoken' => 'The change was not accepted. Review it in the app.'], 'completed_at' => now()])->save();
            throw $e;
        } catch (\Throwable $e) {
            $rejected = $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500;
            $row->forceFill(['state' => $rejected ? 'failed' : 'uncertain', 'result' => ['spoken' => $rejected
                ? 'The action was not accepted. Review it in the app.' : 'The result could not be confirmed. Check the app before trying again.']])->save();
            if (! $rejected) {
                report($e);
            }
            if ($rejected) {
                throw $e;
            }
        }

        return $this->payload($row->fresh());
    }

    private function outcome(MobileAssistantAction $row, array $response): array
    {
        $data = $response['data'] ?? $response;
        if (in_array($row->action, ['sms.send', 'email.send'], true)) {
            // A group inbox copy is not a carrier delivery. Aggregate the actual recipients.
            $messages = Message::where('metadata->assistant_action_id', $row->id)->where('to_address', 'not like', 'group:%')->orderBy('id')->get()->keyBy('to_address');
            $expected = count($row->proposal['recipients'] ?? []);
            if ($expected > 1) {
                $statuses = $messages->pluck('status')->map(fn ($status) => strtoupper((string) $status));
                $sent = $statuses->filter(fn ($status) => in_array($status, ['SENT', 'DELIVERED'], true))->count();
                $delivered = $statuses->filter(fn ($status) => $status === 'DELIVERED')->count();
                $failed = $statuses->filter(fn ($status) => in_array($status, ['FAILED', 'BLOCKED', 'CANCELLED'], true))->count();
                $missing = max(0, $expected - $messages->count());
                $pending = $messages->count() - $sent - $failed;
                $state = $failed || $missing ? 'partial' : ($pending ? 'pending' : 'completed');
                $spoken = $delivered === $expected ? 'Message delivered to all '.$expected.' recipients.'
                    : 'Message sent to '.$sent.' of '.$expected.' recipients.'.($pending ? ' '.$pending.' pending delivery.' : '')
                    .($failed || $missing ? ' '.($failed + $missing).' failed or could not be confirmed. Check the conversation before retrying.' : '');

                return ['state' => $state, 'spoken' => $spoken, 'delivery_status' => strtoupper($state),
                    'recipient_count' => $expected, 'sent' => $sent, 'failed' => $failed, 'unconfirmed' => $missing, 'pending' => $pending,
                    'route' => $row->proposal['route']];
            }
            $message = $messages->last();
            $status = strtoupper((string) ($message?->status ?? $data['status'] ?? 'PENDING'));
            $state = match ($status) {
                'DELIVERED', 'SENT' => 'completed', 'FAILED', 'BLOCKED', 'CANCELLED' => 'failed', default => 'pending'
            };

            return ['state' => $state, 'spoken' => match ($status) {
                'DELIVERED' => 'Message delivered.', 'SENT' => 'Message sent.', 'BLOCKED' => 'The message was blocked by the sending rules.',
                'FAILED', 'CANCELLED' => 'The message was not sent.', default => 'The message is queued. Delivery is pending.',
            }, 'message_id' => $message?->id, 'delivery_status' => $status, 'route' => $row->proposal['route']];
        }
        $spoken = match ($row->action) {
            'note.create' => 'Note saved.', 'availability.create' => 'Calendar updated.', 'support.create' => 'Support request saved.',
            'shoot.hold' => 'Shoot placed on hold.', 'shoot.reschedule', 'shoot.resume' => 'Appointment saved.', default => 'Change saved.',
        };
        if (($response['notifications']['failed'] ?? 0) > 0) {
            $spoken .= ' Some notifications need attention in the app.';
        }
        // A note response contains the note ID; its destination is still the owning shoot.
        $recordID = $row->proposal['record_id'] ?? (isset($data['id']) ? (string) $data['id'] : null);

        return ['state' => 'completed', 'spoken' => $spoken, 'record_id' => $recordID, 'route' => $row->proposal['route']];
    }

    public function receipt(User $actor, string $id): array
    {
        $row = $this->owned($actor, $id);
        $this->capabilities->require($actor, $row->action);
        // Re-check record access before returning dictated content, even for an old successful receipt.
        $this->proposals->authorizeReceipt($actor, $row->action, $row->proposal['params']);
        if ($row->state === 'prepared' && $row->expires_at->isPast()) {
            $row->forceFill(['state' => 'expired'])->save();
        }
        if (in_array($row->action, ['sms.send', 'email.send'], true) && in_array($row->state, ['executing', 'uncertain', 'pending', 'failed', 'partial', 'completed'], true)) {
            if (Message::where('metadata->assistant_action_id', $row->id)->exists()) {
                $result = $this->outcome($row, []);
                $row->forceFill(['state' => $result['state'], 'result' => $result])->save();
            }
        }

        return $this->payload($row);
    }

    public function cancel(User $actor, string $id): array
    {
        $row = $this->owned($actor, $id);
        $this->capabilities->require($actor, $row->action);
        $this->proposals->authorizeReceipt($actor, $row->action, $row->proposal['params']);
        MobileAssistantAction::whereKey($row->id)->where('state', 'prepared')->update(['state' => 'cancelled', 'updated_at' => now()]);

        return $this->payload($row->fresh());
    }

    public function claimHandoff(User $actor, string $id): array
    {
        $row = $this->owned($actor, $id);
        abort_unless($row->action === 'call.start' && $row->state === 'handoff' && $row->expires_at->isFuture(), 409, 'This calling request has already been used or expired.');
        $this->validateCurrent($actor, $row);
        $claimed = LockedWrite::run(fn () => MobileAssistantAction::whereKey($id)->where('state', 'handoff')
            ->where('expires_at', '>', now())->update(['state' => 'consumed', 'updated_at' => now()]), 'assistant.call_handoff');
        abort_unless($claimed, 409, 'This calling request has already been used.');

        return $this->payload($row->fresh());
    }

    private function payload(MobileAssistantAction $row): array
    {
        $result = $row->result;
        if (! $result && $row->state !== 'prepared') {
            $result = ['spoken' => match ($row->state) {
                'executing', 'pending' => 'This request is still processing. Check its status in R/E Pro before trying again.',
                'cancelled' => 'Request cancelled.', 'expired' => 'This confirmation expired. Ask again.',
                default => 'Check this request in R/E Pro before trying again.',
            }];
        }

        return ['schema_version' => 1, 'id' => $row->id, 'owner_id' => (string) $row->user_id, 'request_id' => $row->request_id,
            'action' => $row->action, 'state' => $row->state, 'mode' => $row->proposal['mode'], 'summary' => $row->proposal['summary'],
            'expires_at' => $row->expires_at->toIso8601String(), 'route' => $row->proposal['route'],
            'record_id' => $row->proposal['record_id'], 'result' => $result];
    }
}
