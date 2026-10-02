<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiResponseException;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\TravelLocationResolver;
use App\Services\Scheduling\TravelTimeEstimator;
use App\Services\Scheduling\VisitPlanBuilder;
use App\Services\Scheduling\WriteSchedulePlan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class HybridScheduleFeasibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private User $admin;

    private Service $service;

    private int $gap = 15;

    private bool $unknown = false;

    private array $legs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['availability.hybrid_travel_enabled' => true, 'app.timezone' => 'UTC',
            'availability.fallback_start_time' => '00:00', 'availability.fallback_end_time' => '23:59']);
        $this->photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->service = Service::factory()->create(['shoot_duration_minutes' => 15]);
        $locations = Mockery::mock(TravelLocationResolver::class);
        $locations->shouldReceive('reset')->andReturnNull();
        $locations->shouldReceive('forPayload')->andReturnUsing(fn ($p, $s = null) => $this->location($p['address'] ?? $s?->address ?? '20 Candidate Road'));
        $locations->shouldReceive('forShoot')->andReturnUsing(fn ($s) => $this->location($s->address));
        $this->app->instance(TravelLocationResolver::class, $locations);
        $estimator = Mockery::mock(TravelTimeEstimator::class);
        $estimator->shouldReceive('reset')->andReturnNull();
        $estimator->shouldReceive('estimate')->andReturnUsing(function ($a, $b, $departure) {
            $this->legs[] = [$a['building_key'], $b['building_key'], $departure->toIso8601String()];
            $same = $a['building_key'] === $b['building_key'];

            return ['source' => $same ? 'same_building' : ($this->unknown ? 'unavailable' : 'google_routes'),
                'required_minutes' => $same ? 0 : ($this->unknown ? null : $this->gap),
                'review_required' => ! $same && $this->unknown, 'reason_code' => $this->unknown ? 'no_route' : null,
                'drive_minutes' => $same ? 0 : 10, 'distance_miles' => 1, 'attribution' => $same ? null : 'Google Maps'];
        });
        $this->app->instance(TravelTimeEstimator::class, $estimator);
    }

    private function location(string $address): array
    {
        return ['verified' => true, 'building_key' => preg_replace('/ Unit .+$/', '', $address), 'address' => $address];
    }

    private function payload(string $time = '09:00', string $address = '20 Candidate Road'): array
    {
        return ['photographer_id' => $this->photographer->id, 'scheduled_at' => '2026-10-02T'.$time.':00-04:00',
            'timezone' => 'America/New_York', 'address' => $address, 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21205',
            'services' => [['id' => $this->service->id, 'duration_minutes' => 15]], 'action_mode' => 'create'];
    }

    private function booked(string $time, string $address = '10 Prior Road', int $duration = 15, ?User $photographer = null): Shoot
    {
        $photographer ??= $this->photographer;
        $start = Carbon::parse('2026-10-02 '.$time, 'America/New_York')->utc();
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id, 'scheduled_at' => $start,
            'scheduled_date' => '2026-10-02', 'time' => $time, 'timezone' => 'America/New_York', 'address' => $address,
            'status' => Shoot::STATUS_SCHEDULED]);
        $shoot->services()->attach($this->service->id, ['duration_minutes' => $duration, 'scheduled_at' => $start,
            'photographer_id' => $photographer->id, 'workflow_status' => 'scheduled']);

        return $shoot;
    }

    private function evaluate(array $payload, ?Shoot $shoot = null, bool $alternatives = false): array
    {
        return app(ScheduleFeasibilityService::class)->evaluate($payload, $shoot, $this->admin, $alternatives);
    }

    public function test_michael_exact_fifteen_minute_gap_and_longer_drive_alternative(): void
    {
        $this->booked('08:30', '615 North Highland Avenue');
        $result = $this->evaluate($this->payload('09:00', '5806 Winner Avenue'));
        $this->assertSame('available', $result['status']);
        $this->assertSame(15, $result['transitions'][0]['available_minutes']);
        $this->gap = 25;
        $result = $this->evaluate($this->payload('09:00'), null, true);
        $this->assertSame('conflict', $result['status']);
        $this->assertSame(10, $result['transitions'][0]['shortfall_minutes']);
        $this->assertSame('2026-10-02T13:10:00+00:00', $result['transitions'][0]['earliest_start']);
        $this->assertSame('2026-10-02T13:10:00+00:00', $result['alternatives'][0]['scheduled_at']);
        $this->assertLessThanOrEqual(3, count($result['alternatives']));
    }

    public function test_jaz_separate_units_allow_back_to_back_but_capture_cannot_overlap(): void
    {
        $this->booked('09:00', '12800 Middlebrook Road Unit 333');
        $result = $this->evaluate($this->payload('09:15', '12800 Middlebrook Road Unit 206'));
        $this->assertSame('available', $result['status']);
        $this->assertSame('same_building', $result['transitions'][0]['source']);
        $this->assertSame(0, $result['transitions'][0]['required_minutes']);
        $this->legs = [];
        $result = $this->evaluate($this->payload('09:00', '12800 Middlebrook Road Unit 206'));
        $this->assertContains('capture_overlap', $result['reason_codes']);
        $this->assertFalse($result['can_override']);
        $this->assertSame([], $this->legs);
    }

    public function test_outgoing_travel_is_directional_and_uses_candidate_end_as_departure(): void
    {
        $this->booked('09:00', '10 Before Road');
        $this->booked('10:00', '30 Next Road');
        $this->gap = 25;
        $result = $this->evaluate($this->payload('09:40'));
        $this->assertSame('conflict', $result['status']);
        $out = collect($result['transitions'])->firstWhere('direction', 'outgoing');
        $this->assertSame(5, $out['available_minutes']);
        $this->assertSame('2026-10-02T13:20:00+00:00', $out['latest_start']);
        $this->assertContains(['20 Candidate Road', '30 Next Road', '2026-10-02T13:55:00+00:00'], $this->legs);
    }

    public function test_unknown_route_needs_review_and_exception_reason(): void
    {
        $this->booked('08:30');
        $this->unknown = true;
        $payload = $this->payload();
        $result = $this->evaluate($payload);
        $this->assertSame('review_required', $result['status']);
        $this->assertTrue($result['can_override']);
        app(ScheduleFeasibilityService::class)->assertResult($result, $payload + [
            'travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_confirmation_version' => $result['confirmation_version'],
            'travel_override_reason' => 'Photographer confirmed direct access'], null, $this->admin);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ScheduleFeasibilityService::class)->assertResult($result, $payload + ['travel_override' => true, 'travel_override_confirmed' => true], null, $this->admin);
    }

    public function test_travel_override_requires_deliberate_acknowledgment_and_reason(): void
    {
        $this->booked('08:30');
        $this->gap = 25;
        $payload = $this->payload() + ['travel_override' => true, 'travel_override_reason' => 'Driver has reviewed this gap'];
        $result = $this->evaluate($payload);
        foreach ([[], ['travel_override_confirmed' => false]] as $confirmation) {
            try {
                app(ScheduleFeasibilityService::class)->assertResult($result, $payload + $confirmation, null, $this->admin);
                $this->fail('An old override checkbox is not deliberate confirmation.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('travel_override_confirmed', $exception->errors());
            }
        }
        app(ScheduleFeasibilityService::class)->assertResult($result, $payload + ['travel_override_confirmed' => true,
            'travel_override_confirmation_version' => $result['confirmation_version']], null, $this->admin);
        $client = User::factory()->create(['role' => 'client']);
        try {
            app(ScheduleFeasibilityService::class)->assertResult($result, $payload + ['travel_override_confirmed' => true], null, $client);
            $this->fail('Confirmation must not grant a client override authority.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_secondary_assignment_is_checked_and_independent_visit_gap_remains_available(): void
    {
        $secondary = User::factory()->photographer()->create();
        $this->booked('09:00', 'Secondary Road', 15, $secondary);
        $second = Service::factory()->create(['shoot_duration_minutes' => 15]);
        $payload = $this->payload();
        $payload['services'][] = ['id' => $second->id, 'photographer_id' => $secondary->id, 'duration_minutes' => 15];
        $result = $this->evaluate($payload);
        $this->assertCount(2, $result['visits']);
        $this->assertContains('capture_overlap', $result['reason_codes']);
        $payload['services'][1]['scheduled_at'] = '2026-10-02T10:00:00-04:00';
        $this->assertSame('available', $this->evaluate($payload)['status']);
    }

    public function test_confirmation_matches_the_write_plan_and_changed_travel_requires_fresh_confirmation(): void
    {
        $this->booked('08:30');
        $this->gap = 25;
        $payload = $this->payload();
        $preview = $this->evaluate($payload);
        $write = app(WriteSchedulePlan::class)->services($payload, $payload['services'],
            Carbon::parse($payload['scheduled_at'])->utc(), $this->photographer->id, $payload['timezone'], 'create');
        $writeResult = $this->evaluate($write);
        $this->assertSame($preview['confirmation_version'], $writeResult['confirmation_version']);
        $service = app(ScheduleFeasibilityService::class);
        $confirmed = $write + ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_reason' => 'Photographer has reviewed this gap',
            'travel_override_confirmation_version' => $preview['confirmation_version']];
        $service->assertResult($writeResult, $confirmed, null, $this->admin);
        $this->gap = 30;
        $changed = $this->evaluate($write);
        try {
            $service->assertResult($changed, $confirmed, null, $this->admin);
            $this->fail('A changed route estimate needs another deliberate confirmation.');
        } catch (PublicApiResponseException $exception) {
            $fresh = $exception->getResponse()->getData(true);
            $this->assertSame(422, $exception->getResponse()->getStatusCode());
            $this->assertSame(30, $fresh['feasibility']['transitions'][0]['required_minutes']);
            $this->assertSame($changed['confirmation_version'], $fresh['feasibility']['confirmation_version']);
            $this->assertNotSame($preview['confirmation_version'], $fresh['feasibility']['confirmation_version']);
        }
    }

    public function test_multiple_proposed_visits_check_each_other_and_keep_internal_gap_zero(): void
    {
        $second = Service::factory()->create(['shoot_duration_minutes' => 15]);
        $payload = $this->payload();
        $payload['services'][] = ['id' => $second->id, 'scheduled_at' => '2026-10-02T09:15:00-04:00', 'duration_minutes' => 15];
        $result = $this->evaluate($payload);
        $this->assertSame('available', $result['status']);
        $this->assertSame(0, $result['transitions'][0]['required_minutes']);
        $payload['services'][1]['scheduled_at'] = '2026-10-02T09:10:00-04:00';
        $this->assertContains('capture_overlap', $this->evaluate($payload)['reason_codes']);
    }

    public function test_separate_plans_check_travel_between_submissions(): void
    {
        $result = app(ScheduleFeasibilityService::class)->evaluatePlans([
            ['payload' => $this->payload('09:00', '10 First Road')],
            ['payload' => $this->payload('09:15', '20 Second Road')],
        ], $this->admin);
        $this->assertSame('conflict', $result['status']);
        $this->assertSame(15, $result['transitions'][0]['shortfall_minutes']);
    }

    public function test_hours_are_hard_constraints_even_for_admin(): void
    {
        config(['availability.fallback_start_time' => '09:00']);
        $result = $this->evaluate($this->payload('08:30'));
        $this->assertContains('outside_working_hours', $result['reason_codes']);
        $this->assertFalse($result['can_override']);
    }

    public function test_adjacent_date_visit_and_dst_use_absolute_instants(): void
    {
        $before = $this->booked('23:40', 'Yesterday Road');
        $before->update(['scheduled_at' => '2026-10-02T03:40:00+00:00']);
        $before->serviceItems()->update(['scheduled_at' => '2026-10-02 03:40:00']);
        $result = $this->evaluate($this->payload('00:05'));
        $this->assertSame('conflict', $result['status']);
        $this->assertSame(10, $result['transitions'][0]['available_minutes']);
        $payload = $this->payload();
        $payload['scheduled_at'] = '2026-11-01T01:30:00-05:00';
        $visits = app(VisitPlanBuilder::class)->build($payload);
        $this->assertSame('2026-11-01T06:30:00+00:00', $visits[0]['start']);
    }

    public function test_notes_only_edit_preserves_existing_itinerary_without_reopening_travel(): void
    {
        $shoot = $this->booked('09:00');
        $payload = ['action_mode' => 'update', 'notes' => 'Updated access note'];
        $result = $this->evaluate($payload, $shoot);
        $this->assertSame('available', $result['status']);
        $this->assertSame([], $this->legs);
    }

    public function test_endpoint_auth_exclusion_permission_and_privacy(): void
    {
        $this->postJson('/api/photographer/availability/feasibility', $this->payload())->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $neighbor = $this->booked('08:30', 'CONFIDENTIAL NEIGHBOR ADDRESS');
        $this->postJson('/api/photographer/availability/feasibility', $this->payload() + ['exclude_shoot_id' => $neighbor->id])
            ->assertUnprocessable()->assertJsonValidationErrors('exclude_shoot_id');
        $this->postJson('/api/photographer/availability/feasibility', $this->payload() + ['shoot_id' => $neighbor->id])->assertForbidden();
        $this->postJson('/api/photographer/availability/feasibility', $this->payload() + ['travel_location_confirmed' => true])->assertForbidden();
        $response = $this->postJson('/api/photographer/availability/feasibility', $this->payload())->assertOk();
        $this->assertStringNotContainsString('CONFIDENTIAL', $response->getContent());
        $this->assertFalse($response->json('data.can_override'));
        $this->assertArrayNotHasKey('budget', $response->json('data'));
        $this->assertArrayNotHasKey('_plans', $response->json('data'));
    }

    public function test_disabled_flag_restores_fixed_policy_without_evaluation(): void
    {
        config(['availability.hybrid_travel_enabled' => false]);
        $this->assertFalse($this->evaluate($this->payload())['enabled']);
        $this->assertSame([], $this->legs);
    }

    public function test_legacy_availability_endpoint_shares_selected_itinerary_evaluator(): void
    {
        Sanctum::actingAs($this->admin);
        $this->booked('08:30');
        $this->gap = 25;
        $this->postJson('/api/photographer/availability/check', [
            'photographer_id' => $this->photographer->id, 'date' => '2026-10-02',
            'selected_visit' => $this->payload(),
        ])->assertOk()->assertJsonPath('hybrid_travel_enabled', true)
            ->assertJsonPath('travel_feasibility.status', 'conflict')
            ->assertJsonPath('travel_feasibility.transitions.0.required_minutes', 25);
    }
}
