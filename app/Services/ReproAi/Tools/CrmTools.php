<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDripCampaign;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmEventPresenter;
use App\Services\Crm\Robbie\CrmContactBrief;
use App\Services\Crm\Robbie\CrmPlanDay;
use App\Services\Crm\Robbie\CrmProposalService;
use Illuminate\Validation\ValidationException;

/**
 * Robbie's Sales CRM tools. Each runs as the signed-in user, through the same
 * owner scope as the screens, so a rep's Robbie can only see that rep's
 * contacts. The propose_* tools never change anything: they create a
 * proposal the user confirms in the panel.
 */
class CrmTools
{
    public const NAMES = [
        'crm_search_contacts', 'crm_get_contact', 'crm_contact_brief', 'crm_plan_day', 'crm_list_email_templates', 'crm_list_drips',
        'crm_propose_note', 'crm_propose_event', 'crm_propose_stage_change', 'crm_propose_email', 'crm_propose_drip_enrolment',
    ];

    public function __construct(private readonly CrmAccess $access) {}

    /** OpenAI function definitions for the CRM chat. */
    public static function definitions(): array
    {
        $fn = fn (string $name, string $description, array $properties = [], array $required = []) => [
            'type' => 'function',
            'function' => ['name' => $name, 'description' => $description, 'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required]],
        ];
        $id = ['type' => 'integer', 'description' => 'CRM contact id'];

        return [
            $fn('crm_search_contacts', 'Find the user’s CRM contacts by name, company, email or phone, optionally by stage.', ['query' => ['type' => 'string'], 'stage' => ['type' => 'string', 'enum' => CrmContact::STAGES], 'limit' => ['type' => 'integer']]),
            $fn('crm_get_contact', 'Get one contact’s details.', ['contact_id' => $id], ['contact_id']),
            $fn('crm_contact_brief', 'Facts and recent history for one contact: stage, last contact, notes, emails, shoots, quotes, next follow-up.', ['contact_id' => $id], ['contact_id']),
            $fn('crm_plan_day', 'The user’s work for today in priority order with reasons.'),
            $fn('crm_list_email_templates', 'Approved email templates the user can send from.'),
            $fn('crm_list_drips', 'Live drip campaigns a contact can be enrolled in.'),
            $fn('crm_propose_note', 'Propose adding a note to a contact. The user confirms it.', ['contact_id' => $id, 'body' => ['type' => 'string']], ['contact_id', 'body']),
            $fn('crm_propose_event', 'Propose scheduling a follow-up. The user confirms it.', ['contact_id' => $id, 'type' => ['type' => 'string', 'enum' => ['call', 'email', 'checkin_call', 'checkin_email', 'meeting', 'other']], 'starts_at' => ['type' => 'string', 'description' => 'ISO 8601 date-time in the user’s time zone'], 'duration_min' => ['type' => 'integer'], 'note' => ['type' => 'string']], ['contact_id', 'type', 'starts_at']),
            $fn('crm_propose_stage_change', 'Propose moving a contact to another stage, or marking it Lost with a reason. The user confirms it.', ['contact_id' => $id, 'stage' => ['type' => 'string', 'enum' => [...CrmContact::PIPELINE_STAGES, CrmContact::STAGE_LOST]], 'lost_reason' => ['type' => 'string', 'enum' => CrmContact::LOST_REASONS], 'lost_note' => ['type' => 'string']], ['contact_id', 'stage']),
            $fn('crm_propose_email', 'Propose an email from an approved template, optionally with adjusted wording inside the template body. The user reviews and sends it.', ['contact_id' => $id, 'template_id' => ['type' => 'integer'], 'subject' => ['type' => 'string'], 'body_html' => ['type' => 'string', 'description' => 'Simple HTML: p, br, strong, em, ul, li, a only']], ['contact_id', 'template_id']),
            $fn('crm_propose_drip_enrolment', 'Propose enrolling a contact in a live drip. The user confirms it.', ['contact_id' => $id, 'campaign_id' => ['type' => 'integer']], ['contact_id', 'campaign_id']),
        ];
    }

    public function searchContacts(array $params, array $context = []): array
    {
        $this->actor($context);
        $q = trim((string) ($params['query'] ?? ''));
        $items = CrmContact::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.mb_strtolower($q).'%';
                $digits = preg_replace('/\D+/', '', $q);
                $query->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(company) LIKE ?', [$like])
                    ->orWhere('email_normalized', 'like', $like)
                    ->when(strlen((string) $digits) >= 4, fn ($w) => $w->orWhere('phone_digits', 'like', '%'.$digits.'%')));
            })
            ->when(in_array($params['stage'] ?? null, CrmContact::STAGES, true), fn ($query) => $query->where('stage', $params['stage']))
            ->orderByDesc('updated_at')->limit(max(1, min(25, (int) ($params['limit'] ?? 10))))->get();

        return ['success' => true, 'contacts' => $items->map(fn (CrmContact $c) => $this->summary($c))->all()];
    }

    public function getContact(array $params, array $context = []): array
    {
        $this->actor($context);
        $contact = $this->contact($params);
        if (! $contact) {
            return ['success' => false, 'error' => 'Contact not found.'];
        }

        return ['success' => true, 'contact' => $this->summary($contact) + [
            'title' => $contact->title, 'address' => $contact->address, 'segment' => $contact->segment, 'client_type' => $contact->client_type,
            'lead_source' => $contact->lead_source, 'lost_reason' => $contact->lost_reason, 'email_opt_out' => (bool) $contact->email_opt_out_at,
            'next_event' => app(CrmEventPresenter::class)->nextFor($contact),
        ]];
    }

    public function contactBrief(array $params, array $context = []): array
    {
        $this->actor($context);
        $contact = $this->contact($params);
        if (! $contact) {
            return ['success' => false, 'error' => 'Contact not found.'];
        }
        $brief = app(CrmContactBrief::class);
        $facts = $brief->facts($contact);
        unset($facts['drip']['sends']);

        return ['success' => true, 'facts' => $facts, 'summary' => $brief->summary($facts)];
    }

    public function planDay(array $params, array $context = []): array
    {
        return ['success' => true, 'items' => app(CrmPlanDay::class)->for($this->actor($context))];
    }

    public function listEmailTemplates(array $params, array $context = []): array
    {
        $this->actor($context);
        $items = CrmEmailTemplate::query()->whereNotNull('approved_version_id')->where('status', '!=', 'retired')->whereIn('kind', ['one_off', 'campaign', 'drip'])
            ->with('approvedVersion')->orderBy('name')->limit(50)->get();

        return ['success' => true, 'templates' => $items->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'kind' => $t->kind, 'subject' => $t->approvedVersion?->subject])->all()];
    }

    public function listDrips(array $params, array $context = []): array
    {
        $this->actor($context);

        return ['success' => true, 'drips' => CrmDripCampaign::query()->where('status', 'live')->orderBy('id')->get(['id', 'name', 'length_key'])->toArray()];
    }

    public function proposeNote(array $params, array $context = []): array
    {
        return $this->propose($context, 'note', $params, ['body' => (string) ($params['body'] ?? '')], fn ($c) => 'Add a note to '.$c->name);
    }

    public function proposeEvent(array $params, array $context = []): array
    {
        $payload = collect($params)->only(['type', 'starts_at', 'duration_min', 'note'])->all();

        return $this->propose($context, 'event', $params, $payload, fn ($c) => 'Schedule a '.str_replace('_', ' ', (string) ($params['type'] ?? 'follow-up')).' with '.$c->name.' on '.date('D j M, g:i a', strtotime((string) ($params['starts_at'] ?? 'now'))));
    }

    public function proposeStageChange(array $params, array $context = []): array
    {
        $payload = collect($params)->only(['stage', 'lost_reason', 'lost_note'])->all();

        return $this->propose($context, 'stage', $params, $payload, fn ($c) => ($params['stage'] ?? '') === CrmContact::STAGE_LOST
            ? 'Mark '.$c->name.' as Lost ('.(CrmContact::LOST_REASON_LABELS[$params['lost_reason'] ?? ''] ?? 'no reason').')'
            : 'Move '.$c->name.' to '.(CrmContact::STAGE_LABELS[$params['stage'] ?? ''] ?? $params['stage'] ?? '?'));
    }

    public function proposeEmail(array $params, array $context = []): array
    {
        $payload = collect($params)->only(['template_id', 'subject', 'body_html'])->all();

        return $this->propose($context, 'email', $params, $payload, fn ($c) => 'Email '.$c->name.(isset($params['subject']) ? ': '.$params['subject'] : ''));
    }

    public function proposeDripEnrolment(array $params, array $context = []): array
    {
        $drip = CrmDripCampaign::query()->find((int) ($params['campaign_id'] ?? 0));

        return $this->propose($context, 'drip', $params, ['campaign_id' => (int) ($params['campaign_id'] ?? 0)], fn ($c) => 'Enrol '.$c->name.' in '.($drip?->name ?? 'a drip'));
    }

    private function propose(array $context, string $kind, array $params, array $payload, callable $summary): array
    {
        $user = $this->actor($context);
        $contact = $this->contact($params);
        if (! $contact) {
            return ['success' => false, 'error' => 'Contact not found.'];
        }
        try {
            $proposal = app(CrmProposalService::class)->propose($user, $kind, $contact, $payload, $summary($contact));
        } catch (ValidationException $e) {
            return ['success' => false, 'error' => collect($e->errors())->flatten()->first() ?? 'That can’t be proposed.'];
        }

        return ['success' => true, 'proposal_id' => $proposal->id, 'summary' => $proposal->summary, 'note' => 'Shown to the user to confirm. Nothing has happened yet.'];
    }

    /**
     * The tools only run for the signed-in person, so the owner scope applies.
     * A call from a context without that person (phone, SMS) is refused.
     */
    private function actor(array $context): User
    {
        $user = $context['user'] ?? null;
        $signedIn = auth()->user();
        if (! $user instanceof User || ! $signedIn instanceof User || (int) $signedIn->id !== (int) $user->id || ! $this->access->can($user, 'crm-contacts', 'view')) {
            throw new \RuntimeException('Sales CRM tools are only available to a signed-in CRM user.');
        }

        return $user;
    }

    private function contact(array $params): ?CrmContact
    {
        return CrmContact::query()->find((int) ($params['contact_id'] ?? 0));
    }

    private function summary(CrmContact $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'company' => $c->company, 'email' => $c->email, 'phone' => $c->phone,
            'stage' => $c->stage, 'stage_label' => $c->stageLabel(), 'market' => $c->market_state,
            'last_contacted' => $c->last_contacted_at?->toDateString(), 'health' => $c->health,
        ];
    }
}
