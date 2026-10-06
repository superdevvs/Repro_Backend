<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupportTicketService
{
    public function __construct(private RolePermissionService $permissions) {}

    public function canManage(User $user): bool
    {
        return $user->isAccountEligibleForAuthentication()
            && \App\Services\Messaging\DashboardMessagingPolicy::staffRole($user->role)
            && $this->permissions->userCan($user, 'support', 'manage')
            && $this->permissions->userCan($user, 'support', 'view');
    }

    public function visible(User $user): Builder
    {
        return SupportTicket::query()->when(! $this->canManage($user), fn ($query) => $query->where('requester_id', $user->id));
    }

    public function find(User $user, int $id): SupportTicket
    {
        return $this->visible($user)->findOrFail($id);
    }

    public function create(User $user, array $data): SupportTicket
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($user, $data) {
            $existing = SupportTicket::where('requester_id', $user->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->subject === $data['subject'] && $existing->category === $data['category']
                    && $existing->page_path === ($data['page_path'] ?? null)
                    && $existing->messages()->oldest('id')->value('body') === $data['body']
                    && SupportAttachmentService::fingerprint($existing->messages()->oldest('id')->first()->attachments_json ?? []) === SupportAttachmentService::fingerprint($data['attachments_json'] ?? []), 409, 'This submission key was already used.');

                return $existing;
            }
            $ticket = SupportTicket::create([
                'requester_id' => $user->id, 'request_key' => $data['request_key'],
                'subject' => $data['subject'], 'category' => $data['category'],
                'page_path' => $data['page_path'] ?? null, 'status' => 'open', 'priority' => 'normal', 'version' => 1,
            ]);
            $ticket->messages()->create(['author_id' => $user->id, 'request_key' => $data['request_key'], 'body' => $data['body'], 'attachments_json' => $data['attachments_json'] ?? [], 'kind' => 'opened']);

            return $ticket;
        }), 'support.create');
    }

    public function reply(User $user, int $id, array $data): SupportTicket
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($user, $id, $data) {
            $ticket = $this->find($user, $id);
            $internal = (bool) ($data['internal'] ?? false);
            abort_if($internal && ! $this->canManage($user), 403);
            $existing = $ticket->messages()->where('author_id', $user->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->body === $data['body'] && $existing->internal === $internal
                    && SupportAttachmentService::fingerprint($existing->attachments_json ?? []) === SupportAttachmentService::fingerprint($data['attachments_json'] ?? []), 409, 'This reply key was already used.');

                return $ticket;
            }
            $ticket->messages()->create(['author_id' => $user->id, 'request_key' => $data['request_key'], 'body' => $data['body'], 'attachments_json' => $data['attachments_json'] ?? [], 'internal' => $internal, 'kind' => $internal ? 'note' : 'reply']);
            // A new public customer reply reopens a resolved case; internal notes do not.
            $status = (! $internal && $ticket->requester_id === $user->id && in_array($ticket->status, ['resolved', 'waiting'], true)) ? 'open' : $ticket->status;
            $ticket->update(['status' => $status, 'version' => $ticket->version + 1]);

            return $ticket;
        }), 'support.reply');
    }

    public function update(User $user, int $id, array $data): SupportTicket
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($user, $id, $data) {
            $ticket = $this->find($user, $id);
            abort_unless($ticket->version === $data['version'], 409, 'This request changed. Refresh before saving.');
            $manage = $this->canManage($user);
            abort_if(! $manage && (array_key_exists('priority', $data) || array_key_exists('assigned_to', $data)
                || ! in_array($data['status'] ?? null, ['open', 'resolved'], true)), 403);
            if (isset($data['assigned_to'])) {
                $assignee = User::find($data['assigned_to']);
                abort_unless($assignee && $this->canManage($assignee), 422, 'Choose an available support administrator.');
            }
            $updates = array_intersect_key($data, array_flip(['status', 'priority', 'assigned_to']));
            $changes = [];
            foreach ($updates as $key => $value) {
                if ((string) $ticket->{$key} !== (string) $value) {
                    $changes[] = match ($key) {
                        'assigned_to' => $value ? 'Assigned to '.User::find($value)->name : 'Assignment cleared',
                        'status' => 'Status: '.str_replace('_', ' ', $value),
                        default => 'Priority: '.$value,
                    };
                }
            }
            if ($changes !== []) {
                $ticket->update([...$updates, 'version' => $ticket->version + 1]);
                $ticket->messages()->create(['author_id' => $user->id, 'request_key' => (string) Str::uuid(), 'body' => implode(' · ', $changes), 'kind' => 'event']);
            }

            return $ticket;
        }), 'support.update');
    }

    public function payload(SupportTicket $ticket, User $viewer, ?bool $canManage = null): array
    {
        $ticket->loadMissing(['requester:id,name,role', 'assignee:id,name']);

        return [
            'id' => $ticket->id, 'reference' => $ticket->reference(), 'subject' => $ticket->subject,
            'category' => $ticket->category, 'status' => $ticket->status, 'priority' => $ticket->priority,
            'version' => $ticket->version, 'page_path' => $ticket->page_path,
            'requester' => $ticket->requester?->only(['id', 'name', 'role']),
            'assignee' => $ticket->assignee?->only(['id', 'name']),
            'created_at' => $ticket->created_at?->toIso8601String(), 'updated_at' => $ticket->updated_at?->toIso8601String(),
            'can_manage' => $canManage ?? $this->canManage($viewer),
        ];
    }

    public function notifications(User $user, int $limit = 50): Collection
    {
        if (! Schema::hasTable('support_tickets') || ! $this->permissions->userCan($user, 'support', 'view')) {
            return collect();
        }

        return SupportTicketMessage::query()->with('ticket')
            ->whereIn('support_ticket_id', $this->visible($user)->select('id'))
            ->where('author_id', '!=', $user->id)
            ->when(! $this->canManage($user), fn ($q) => $q->where('internal', false))
            ->latest('id')->limit($limit)->get()->map(fn ($message) => [
                'id' => 'support-'.$message->id, 'type' => 'system', 'action' => 'support_updated',
                'message' => $message->ticket->reference().': '.($message->kind === 'opened' ? 'New support request' : 'Support request updated').'.',
                'timestamp' => $message->created_at->toIso8601String(),
                'actionUrl' => '/messaging/email/inbox?tab=support&ticket='.$message->support_ticket_id, 'actionLabel' => 'View request', 'shootId' => null,
            ]);
    }
}
