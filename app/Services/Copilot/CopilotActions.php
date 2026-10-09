<?php

namespace App\Services\Copilot;

use App\Http\Controllers\API\ShootNotesController;
use App\Http\Requests\StoreShootRequest;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RolePermissionService;
use App\Services\Scheduling\ScheduleCommitGuard;
use App\Services\Scheduling\WriteSchedulePlan;
use App\Services\Shoots\Actions\CreateShootAction;
use App\Services\Shoots\Actions\UpdateShootAction;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\Shoots\ShootManagementAccess;
use App\Services\Shoots\ShootMutationSupportService;
use App\Services\Shoots\ShootNotesAccessService;
use App\Support\LockedWrite;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CopilotActions
{
    public function __construct(private readonly CopilotData $data) {}

    public function prepare(string $kind, array $payload, Request $request): array
    {
        $user = $request->user();
        $review = $this->review($kind, $payload, $user);
        $id = (string) Str::uuid();
        $hash = $this->hash($review);
        LockedWrite::run(fn () => DB::table('copilot_drafts')->insert(['id' => $id, 'user_id' => $user->id,
            'grant_id' => $request->attributes->get('copilot_grant')->id, 'kind' => $kind,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'review_hash' => $hash,
            'review' => json_encode($review, JSON_THROW_ON_ERROR),
            'record_revision' => isset($payload['shoot_id']) ? $this->revision($this->data->shoot($payload['shoot_id'], $user)) : null,
            'expires_at' => now()->addMinutes(config('copilot.draft_minutes')), 'created_at' => now(), 'updated_at' => now()]), 'copilot-prepare');

        return ['draft_id' => $id, 'kind' => $kind, 'status' => 'prepared', 'review_hash' => $hash, 'review' => $review,
            'expires_at' => now()->addMinutes(config('copilot.draft_minutes'))->toIso8601String(),
            'next_step' => 'Show this exact preview and obtain explicit approval before commit_action. No action or notifications have occurred.'];
    }

    public function get(string $id, Request $request): array
    {
        $draft = $this->find($id, $request);
        $result = $draft->result ? json_decode($draft->result, true) : null;
        if ($draft->status === 'processing' && $draft->kind === 'booking') {
            $shoot = Shoot::where('copilot_operation_id', $draft->id)->first();
            if ($shoot) {
                $result = ['shoot_id' => $shoot->id, 'record_created' => true,
                    'reconciliation_required' => true, 'message' => 'The shoot exists. Do not repeat the booking. Background notification/provider outcomes require review.'];
            }
        }
        $review = $draft->status === 'prepared' && now()->lt($draft->expires_at)
            ? $this->review($draft->kind, json_decode($draft->payload, true), $request->user()) : null;
        $changed = $review && (! hash_equals($draft->review_hash, $this->hash($review))
            || ($draft->record_revision && ! hash_equals($draft->record_revision, $this->revision($this->data->shoot(json_decode($draft->payload, true)['shoot_id'], $request->user())))));

        return ['draft_id' => $draft->id, 'kind' => $draft->kind, 'status' => $draft->status, 'review_hash' => $draft->review_hash,
            'review_changed' => (bool) $changed,
            'expired' => now()->gte($draft->expires_at), 'expires_at' => $draft->expires_at, 'result' => $result,
            'review' => $review ? json_decode($draft->review, true) : null,
            'next_step' => $draft->status === 'processing' ? 'Reconcile this operation; automatic retry is disabled.' : null];
    }

    public function commit(string $id, string $hash, Request $request): array
    {
        $draft = $this->find($id, $request);
        abort_unless(hash_equals($draft->review_hash, $hash), 409, 'The review does not match this draft.');
        if ($draft->status === 'completed') {
            return json_decode($draft->result, true);
        }
        abort_unless($draft->status === 'prepared', 409, 'This operation needs reconciliation. Use get_draft; do not repeat it.');
        abort_if(now()->gte($draft->expires_at), 410, 'The preview expired. Prepare and review a new action.');
        $payload = json_decode($draft->payload, true);
        $user = $request->user();
        if ($draft->record_revision) {
            abort_unless(hash_equals($draft->record_revision, $this->revision($this->data->shoot($payload['shoot_id'], $user))), 409, 'The shoot changed after review. Prepare a new preview.');
        }
        $review = $this->review($draft->kind, $payload, $user);
        abort_unless(hash_equals($draft->review_hash, $this->hash($review)), 409, 'Pricing, permissions or appointment details changed. Prepare a new preview.');
        $claimed = LockedWrite::run(fn () => DB::table('copilot_drafts')->where('id', $id)->where('status', 'prepared')
            ->update(['status' => 'processing', 'updated_at' => now()]), 'copilot-claim');
        abort_unless($claimed, 409, 'The operation is already being processed. Use get_draft.');
        try {
            if ($draft->kind === 'booking') {
                $booking = $this->bookingRequest($payload, $user);
                $booking->attributes->set('copilot_operation_id', $id);
                $created = app(CreateShootAction::class)->execute($booking, $user);
                $shoot = $created->shoot;
                $result = ['draft_id' => $id, 'shoot_id' => $shoot->id, 'status' => $shoot->status,
                    'workflow_status' => $shoot->workflow_status, 'source_url' => config('copilot.issuer').'/shoots/'.$shoot->id,
                    'message' => $created->treatAsClientRequest ? 'Shoot request submitted for team review.' : 'Shoot created.',
                    'notifications' => 'Normal Repro workflow dispatch uses the reviewed notification preferences. Delivery is not guaranteed by this response.'];
            } elseif ($draft->kind === 'reschedule') {
                $shoot = $this->data->shoot($payload['shoot_id'], $user);
                $input = $this->request(Arr::except($payload, ['shoot_id']), $user);
                $shoot = app(UpdateShootAction::class)->execute($input, $shoot, $user);
                $result = ['draft_id' => $id, 'shoot_id' => $shoot->id, 'status' => $shoot->status, 'scheduled_at' => $shoot->scheduled_at?->toIso8601String(),
                    'message' => 'Appointment updated through the Repro booking workflow.', 'source_url' => config('copilot.issuer').'/shoots/'.$shoot->id];
            } elseif ($draft->kind === 'watch') {
                $shoot = $this->data->shoot($payload['shoot_id'], $user);
                $result = ['draft_id' => $id, ...app(CopilotWatches::class)->configure($payload['shoot_id'], $payload['enabled'], $user, $draft->grant_id)];
            } else {
                $shoot = $this->data->shoot($payload['shoot_id'], $user);
                $result = LockedWrite::run(fn () => DB::transaction(function () use ($payload, $shoot, $user, $id, $draft) {
                    $shoot = $this->data->shoot($shoot->id, $user);
                    abort_unless(hash_equals($draft->record_revision, $this->revision($shoot)), 409, 'The shoot changed after review. Prepare a new preview.');
                    $response = app(ShootNotesController::class)->updateNotesSimple($this->request([$payload['field'] => $payload['text']], $user), $shoot);
                    abort_if($response->getStatusCode() >= 400, $response->getStatusCode(), 'Note update was rejected.');

                    return ['draft_id' => $id, 'shoot_id' => $shoot->id, 'message' => 'Note updated.', 'source_url' => config('copilot.issuer').'/shoots/'.$shoot->id];
                }), 'copilot-note');
            }
            LockedWrite::run(fn () => DB::table('copilot_drafts')->where('id', $id)->update(['status' => 'completed',
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]), 'copilot-result');
            app(AuditLogService::class)->record('copilot.action_committed', $user, $shoot, ['draft_id' => $id, 'kind' => $draft->kind]);

            return $result;
        } catch (\Throwable $error) {
            // Preserve processing after any uncertain failure. Creating a fresh operation is not a safe retry.
            app(\App\Services\ApiErrorResponder::class)::log($error, 'error');
            throw $error;
        }
    }

    private function review(string $kind, array $payload, User $user): array
    {
        if ($kind === 'booking') {
            $request = $this->bookingRequest($payload, $user);
            $normalized = $request->validated();
            $support = app(ShootMutationSupportService::class);
            $support->ensureClientCanBookServices($normalized['client_id'], $normalized['services'], actor: $user);
            $client = $support->ensureClientHasDeliverableEmail($normalized['client_id']);
            $pricing = $support->buildPricingCalculation($normalized['services'], $client, $normalized['state']);
            $names = Service::whereIn('id', array_column($normalized['services'], 'id'))->pluck('name', 'id');
            $services = array_map(fn ($row) => ['name' => $names[$row['id']], ...$row], $normalized['services']);
            $requested = $user->role === 'client' || (int) $normalized['client_id'] === (int) $user->id;
            if (! $requested) {
                $plan = app(WriteSchedulePlan::class)->services($normalized, $normalized['services'], Carbon::parse($normalized['scheduled_at']),
                    $normalized['photographer_id'] ?? null, $normalized['timezone'], 'create');
                app(ScheduleCommitGuard::class)->prepare($plan, null, $user);
            }

            return ['action' => $requested ? 'Submit a shoot request for approval' : 'Create a standard shoot',
                'client' => ['id' => $client->id, 'name' => $client->name], 'property' => Arr::only($normalized, ['address', 'city', 'state', 'zip']),
                'appointment' => Arr::only($normalized, ['scheduled_at', 'timezone', 'photographer_id']),
                'services' => $services, 'pricing' => ['currency' => strtoupper(config('services.stripe.currency', 'USD')), ...Arr::only($pricing, ['base_quote', 'discount_amount', 'tax_amount', 'total_quote'])],
                'notes' => $normalized['shoot_notes'] ?? null, 'notifications' => Arr::only($normalized, ['notify_client', 'notify_photographer']),
                'effects' => $requested ? 'Normal Requested approval flow. This does not confirm a reserved appointment.'
                    : 'Normal booking workflow, configured notifications, calendar synchronization and eligible provider orders may run after submission.'];
        }
        $shoot = $this->data->shoot($payload['shoot_id'], $user);
        if ($kind === 'watch') {
            return ['action' => $payload['enabled'] ? 'Enable a shoot watch' : 'Disable a shoot watch', 'shoot_id' => $shoot->id, 'address' => $shoot->address,
                'effects' => 'Check workflow and media status every five minutes. Notify this account in Repro only when the state changes. Revoking the connection stops the watch.'];
        }
        if ($kind === 'reschedule') {
            abort_unless(app(ShootManagementAccess::class)->canEdit($shoot, $user), 403, 'Appointment editing requires booking management access.');
            $this->assertInstant($payload);
            abort_unless(in_array($shoot->status, ['requested', 'scheduled', 'on_hold'], true), 409, 'Use Repro to review scheduling changes after production has started.');
            abort_if($shoot->units()->count() > 1, 422, 'Use the multi-unit booking editor for this appointment.');
            $plan = app(WriteSchedulePlan::class)->services(array_merge($shoot->only(['address', 'city', 'state', 'zip', 'client_id', 'property_details']), $payload),
                app(\App\Services\Shoots\ShootEditablePayloadService::class)->targetServicesFor($shoot, $payload, $user), Carbon::parse($payload['scheduled_at']), $shoot->photographer_id, $payload['timezone'], 'update');
            if ($shoot->status !== 'requested' || $shoot->workflow_status !== 'requested') {
                app(ScheduleCommitGuard::class)->prepare($plan, $shoot, $user);
            }

            return ['action' => 'Change the shoot appointment', 'shoot_id' => $shoot->id, 'address' => $shoot->address,
                'before' => ['scheduled_at' => $shoot->scheduled_at?->toIso8601String(), 'timezone' => $shoot->timezone],
                'after' => Arr::only($payload, ['scheduled_at', 'timezone']), 'notifications' => Arr::only($payload, ['notify_client', 'notify_photographer']),
                'effects' => 'Existing services and approval status remain subject to the normal booking workflow; availability and travel are rechecked on submission.'];
        }
        abort_unless($kind === 'note' && app(ShootNotesAccessService::class)->canUpdateScalar($shoot, $user, $payload['field']), 403, 'This note field is not editable for your role.');

        return ['action' => 'Update a shoot note', 'shoot_id' => $shoot->id, 'address' => $shoot->address,
            'field' => $payload['field'], 'before' => $shoot->{$payload['field']}, 'after' => $payload['text'], 'effects' => 'Repro note visibility and compatibility rules apply.'];
    }

    private function bookingRequest(array $payload, User $user): StoreShootRequest
    {
        abort_unless(app(RolePermissionService::class)->userCan($user, 'book-shoot', 'create'), 403, 'Booking access is disabled for this account.');
        $this->assertInstant($payload);
        $normalized = Arr::except($payload, ['sqft']);
        $ids = array_column($payload['services'], 'id');
        abort_if(count($ids) !== count(array_unique($ids)), 422, 'Select each service once and use its permitted quantity.');
        $normalized['client_id'] = $payload['client_id'] ?? $user->id;
        $catalog = Service::whereIn('id', array_column($payload['services'], 'id'))->get()->keyBy('id');
        foreach ($normalized['services'] as &$row) {
            $service = $catalog->get($row['id']);
            abort_unless($service, 422, 'Selected service no longer exists.');
            abort_if($service->pricing_type === 'variable' && ! isset($payload['sqft']), 422, 'Square footage is required for variable service pricing.');
            $row['price'] = $service->getPriceForSqft($payload['sqft'] ?? null);
        }
        unset($row);
        $normalized['services'] = app(ShootDurationResolver::class)->withDurations($normalized['services'], $payload['sqft'] ?? null);
        if (isset($payload['sqft'])) {
            $normalized['property_details'] = ['sqft' => $payload['sqft']];
        }
        $request = StoreShootRequest::create('/api/shoots', 'POST', $normalized);
        $request->setContainer(app())->setRedirector(app('redirect'))->setUserResolver(fn () => $user);
        $request->validateResolved();

        return $request;
    }

    private function assertInstant(array $payload): void
    {
        abort_unless(is_string($payload['scheduled_at'] ?? null) && preg_match('/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/', $payload['scheduled_at'], $date), 422, 'Use an ISO timestamp with an explicit timezone offset.');
        abort_unless(checkdate((int) $date[2], (int) $date[3], (int) $date[1]), 422, 'Invalid appointment date.');
        try {
            $instant = Carbon::parse($payload['scheduled_at']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['scheduled_at' => 'Invalid appointment time.']);
        }
        $errors = \DateTime::getLastErrors();
        abort_if($errors && ($errors['warning_count'] || $errors['error_count']), 422, 'Invalid appointment time.');
        abort_unless(in_array($payload['timezone'] ?? '', timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true), 422, 'Choose a valid IANA timezone.');
        abort_unless($instant->getOffset() === $instant->copy()->setTimezone($payload['timezone'])->getOffset(), 422, 'The appointment offset does not match the selected timezone. Check daylight-saving time.');
        abort_if($instant->lt(now()), 422, 'Choose a future appointment.');
    }

    private function request(array $payload, User $user): Request
    {
        $request = Request::create('/api/shoots', 'PATCH', $payload);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function find(string $id, Request $request): object
    {
        $draft = DB::table('copilot_drafts')->where('id', $id)->where('user_id', $request->user()->id)
            ->where('grant_id', $request->attributes->get('copilot_grant')->id)->first();
        abort_unless($draft, 404, 'Action preview not found.');

        return $draft;
    }

    private function revision(Shoot $shoot): string
    {
        return $this->hash(['record' => $shoot->getAttributes(), 'services' => $shoot->services->map(fn ($s) => $s->pivot->getAttributes())->all()]);
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
