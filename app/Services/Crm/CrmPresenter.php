<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmActivity;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmNote;
use App\Models\User;
use Illuminate\Support\Collection;

/** Shapes CRM records for the API. One place, so every screen sees the same fields. */
class CrmPresenter
{
    /** @var array<int, array|null> */
    private array $userCache = [];

    public function __construct(private readonly CrmAccess $access) {}

    public function user(?int $id): ?array
    {
        if (! $id) {
            return null;
        }
        if (! array_key_exists($id, $this->userCache)) {
            $user = User::query()->find($id);
            $this->userCache[$id] = $user ? self::person($user) : null;
        }

        return $this->userCache[$id];
    }

    public static function person(User $user): array
    {
        $name = trim((string) $user->name) ?: (string) $user->email;
        $parts = preg_split('/\s+/', $name) ?: [$name];
        $initials = strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));

        return ['id' => $user->id, 'name' => $name, 'initials' => $initials, 'email' => $user->email];
    }

    /** @param  Collection<int, CrmContact>  $contacts */
    public function primeUsers(Collection $contacts): void
    {
        $ids = $contacts->pluck('assigned_rep_id')->filter()->unique()->diff(array_keys($this->userCache))->values();
        if ($ids->isEmpty()) {
            return;
        }
        User::query()->whereIn('id', $ids)->get()->each(function (User $user) {
            $this->userCache[$user->id] = self::person($user);
        });
        foreach ($ids as $id) {
            $this->userCache[$id] ??= null;
        }
    }

    public function contact(CrmContact $contact, ?User $viewer = null, array $extra = []): array
    {
        $viewer ??= $this->access->user();

        return array_merge([
            'id' => $contact->id,
            'name' => $contact->name,
            'company' => $contact->company,
            'company_id' => $contact->company_id,
            'title' => $contact->title,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'address' => $contact->address,
            'website' => $contact->website,
            'market_state' => $contact->market_state,
            'market_city' => $contact->market_city,
            'market' => self::market($contact),
            'segment' => $contact->segment,
            'client_type' => $contact->client_type,
            'stage' => $contact->stage,
            'stage_label' => $contact->stageLabel(),
            'stage_before_lost' => $contact->stage_before_lost,
            'lost_reason' => $contact->lost_reason,
            'lost_reason_label' => $contact->lost_reason ? (CrmContact::LOST_REASON_LABELS[$contact->lost_reason] ?? $contact->lost_reason) : null,
            'lost_note' => $contact->lost_note,
            'lost_at' => $contact->lost_at?->toIso8601String(),
            'assigned_rep' => $this->user($contact->assigned_rep_id),
            'lead_source' => $contact->lead_source,
            'lead_source_label' => CrmContact::LEAD_SOURCE_LABELS[$contact->lead_source] ?? $contact->lead_source,
            'first_shoot_date' => $contact->first_shoot_date?->toDateString(),
            'client_user_id' => $contact->client_user_id,
            'email_opted_out' => $contact->email_opt_out_at !== null,
            'email_status' => $contact->email_status,
            'sms_consent' => (bool) $contact->sms_consent,
            'needs_review' => (bool) $contact->needs_review,
            'review_reasons' => $contact->review_reasons ?? [],
            'health' => $contact->health,
            'usual_gap_days' => $contact->usual_gap_days,
            'last_shoot_on' => $contact->last_shoot_on?->toDateString(),
            'stage_changed_at' => $contact->stage_changed_at?->toIso8601String(),
            'last_contacted_at' => $contact->last_contacted_at?->toIso8601String(),
            'first_contact_due_at' => $contact->first_contact_due_at?->toIso8601String(),
            'created_at' => $contact->created_at?->toIso8601String(),
            'can' => [
                'edit' => $this->access->can($viewer, 'crm-contacts', 'update'),
                'assign' => $this->access->canAssign($viewer),
            ],
        ], $extra);
    }

    public static function market(CrmContact $contact): ?string
    {
        $parts = array_filter([$contact->market_city, $contact->market_state]);

        return $parts ? implode(', ', $parts) : null;
    }

    public function note(CrmNote $note, ?User $viewer = null): array
    {
        $viewer ??= $this->access->user();

        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $this->user($note->author_id),
            'via_robbie' => (bool) $note->via_robbie,
            'created_at' => $note->created_at?->toIso8601String(),
            'edited_at' => $note->edited_at?->toIso8601String(),
            'has_versions' => $note->edited_at !== null,
            'can_edit' => $viewer !== null && (int) $note->author_id === (int) $viewer->id,
        ];
    }

    public function activity(CrmActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'summary' => $activity->summary,
            'data' => $activity->data ?? [],
            'actor' => $this->user($activity->actor_id),
            'via_robbie' => (bool) $activity->via_robbie,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'occurred_at' => $activity->occurred_at?->toIso8601String(),
        ];
    }
}
