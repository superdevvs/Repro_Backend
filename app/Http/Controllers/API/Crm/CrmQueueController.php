<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDuplicateAlert;
use App\Services\Crm\CrmAssignmentService;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmDuplicateException;
use App\Services\Crm\CrmRepSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin-only queues: leads with no owner, and duplicate attempts by reps. */
class CrmQueueController extends CrmController
{
    public function __construct(
        private readonly CrmAssignmentService $assignment,
        private readonly CrmContactService $contacts,
    ) {}

    public function unassigned(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'assign')) {
            return $denied;
        }

        $leads = CrmContact::query()->whereNull('assigned_rep_id')->where('stage', '!=', CrmContact::STAGE_LOST)
            ->orderBy('created_at')->limit(200)->get();
        $suggester = app(CrmRepSuggester::class);

        $alerts = CrmDuplicateAlert::query()->whereNull('resolved_at')->orderByDesc('created_at')->limit(100)->get();

        return response()->json([
            'leads' => $leads->map(fn (CrmContact $c) => $this->presenter()->contact($c, null, [
                'inquiry' => app(\App\Services\Crm\CrmLeadContext::class)->latestInquiry($c),
                'suggestion' => $suggester->suggest($c),
            ]))->all(),
            'duplicate_attempts' => $alerts->map(fn (CrmDuplicateAlert $a) => [
                'id' => $a->id,
                'attempted_by' => $this->presenter()->user($a->attempted_by),
                'match_type' => $a->match_type,
                'payload' => $a->payload ?? [],
                'origin' => $a->origin,
                'created_at' => $a->created_at?->toIso8601String(),
                'matched_contact' => $a->matchedContact ? $this->presenter()->contact($a->matchedContact) : null,
            ])->all(),
        ]);
    }

    /**
     * keep: leave with the current owner. reassign: give the existing contact to the rep who tried.
     * same_person: nothing to do. allowed_different: create the attempted contact (name matches only).
     */
    public function resolveDuplicate(Request $request, CrmDuplicateAlert $alert): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'assign')) {
            return $denied;
        }
        $data = $request->validate(['resolution' => ['required', Rule::in(CrmDuplicateAlert::RESOLUTIONS)]]);
        abort_if($alert->resolved_at !== null, 409, 'This attempt was already resolved.');

        $actor = $this->actor($request);
        $contact = CrmContact::unscopedForSystem()->find($alert->matched_contact_id);
        $created = null;

        if ($data['resolution'] === 'reassigned' && $contact && $alert->attempted_by) {
            $this->assignment->assign($contact, (int) $alert->attempted_by, $actor);
        }
        if ($data['resolution'] === 'allowed_different') {
            if ($alert->match_type !== 'name') {
                return response()->json(['message' => 'Email and phone matches are always the same contact.'], 422);
            }
            try {
                $created = $this->contacts->create(
                    array_merge(['segment' => 'residential'], array_intersect_key($alert->payload ?? [], array_flip(['name', 'company', 'email', 'phone', 'market_state', 'market_city', 'segment']))),
                    $actor,
                    ['confirm_different_person' => true, 'assigned_rep_id' => $alert->attempted_by],
                );
            } catch (CrmDuplicateException $e) {
                return $this->duplicateResponse($e, $actor);
            }
        }

        $alert->forceFill(['resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution' => $data['resolution']])->save();

        if ($alert->attempted_by) {
            $messages = [
                'kept' => 'An admin reviewed your duplicate contact attempt. The contact stays with its current owner.',
                'reassigned' => 'An admin assigned the existing contact to you.',
                'same_person' => 'An admin confirmed the contact you tried to add already exists.',
                'allowed_different' => 'An admin confirmed it was a different person and added the contact for you.',
            ];
            app(\App\Services\Crm\CrmNoticeService::class)->notify((int) $alert->attempted_by, 'duplicate_resolved', $messages[$data['resolution']], null, $created?->id ?? ($data['resolution'] === 'reassigned' ? $contact?->id : null));
        }

        return response()->json(['resolved' => true, 'created_contact_id' => $created?->id]);
    }
}
