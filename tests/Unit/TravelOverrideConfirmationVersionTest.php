<?php

namespace Tests\Unit;

use App\Exceptions\PublicApiResponseException;
use App\Models\User;
use App\Services\Scheduling\ScheduleFeasibilityService;
use Tests\TestCase;

class TravelOverrideConfirmationVersionTest extends TestCase
{
    private function actor(int $id = 10): User
    {
        return (new User)->forceFill(['id' => $id, 'role' => 'admin']);
    }

    private function warningResult(): array
    {
        return ['enabled' => true, 'available' => false, 'can_override' => true,
            'status' => 'conflict', 'policy_version' => ScheduleFeasibilityService::POLICY_VERSION,
            'schedule_version' => 'photographer-schedule-v1', 'reason_codes' => ['insufficient_travel_time'],
            'location' => ['address_hash' => 'property-a', 'building_key' => 'address:property-a',
                'verified' => true, 'precision' => 'exact', 'latitude' => 39.2, 'longitude' => -76.6],
            'visits' => [['photographer_id' => 20, 'start' => '2026-10-02T13:00:00+00:00',
                'end' => '2026-10-02T13:15:00+00:00', 'duration_minutes' => 15,
                'timezone' => 'America/New_York', 'id' => 'proposed:0:0', 'row_indexes' => [0]]],
            'transitions' => [['id' => 'transient-edge', 'from_visit_id' => 'booked:1:20:123',
                'to_visit_id' => 'proposed:0:0', 'direction' => 'incoming', 'photographer_id' => 20,
                'source' => 'google_routes', 'required_minutes' => 25, 'available_minutes' => 15,
                'reason_code' => 'traffic_aware_route', 'review_required' => false, 'drive_minutes' => 17.2,
                'candidate_start' => '2026-10-02T13:00:00+00:00', 'candidate_end' => '2026-10-02T13:15:00+00:00',
                'neighbor' => ['shoot_id' => 1, 'scheduled_at' => '2026-10-02T12:30:00+00:00',
                    'end_at' => '2026-10-02T12:45:00+00:00', 'timezone' => 'America/New_York',
                    'services' => [['id' => 6, 'name' => 'Exterior Photos'], ['id' => 7, 'name' => 'Drone Photos']]]]],
        ];
    }

    private function confirmationPayload(?string $version = null): array
    {
        return ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_reason' => 'Photographer reviewed the available gap',
            'travel_override_confirmation_version' => $version];
    }

    public function test_canonical_warning_ignores_preview_ids_order_and_subminute_provider_noise(): void
    {
        $service = app(ScheduleFeasibilityService::class);
        $result = $this->warningResult();
        $result['visits'][] = array_replace($result['visits'][0], ['photographer_id' => 21]);
        $result['transitions'][] = array_replace($result['transitions'][0], ['photographer_id' => 21]);
        $equivalent = $result;
        $equivalent['visits'] = array_reverse($equivalent['visits']);
        $equivalent['transitions'] = array_reverse($equivalent['transitions']);
        foreach ($equivalent['visits'] as &$visit) {
            $visit['start'] = '2026-10-02T09:00:00-04:00';
            $visit['end'] = '2026-10-02T09:15:00-04:00';
            $visit['id'] = 'server-generated';
            $visit['row_indexes'] = [102, 104];
        }
        unset($visit);
        foreach ($equivalent['transitions'] as &$edge) {
            $edge['id'] = 'other-edge';
            $edge['to_visit_id'] = 'server-generated';
            $edge['drive_minutes'] = 17.9;
            $edge['neighbor']['services'] = array_reverse($edge['neighbor']['services']);
        }
        unset($edge);
        $equivalent['location']['verified_at'] = now()->toIso8601String();
        $equivalent['_plans'] = [['payload' => ['action_mode' => 'server-adapter']]];
        $version = $service->confirmationVersion($result, $this->actor());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $version);
        $this->assertSame($version, $service->confirmationVersion($equivalent, $this->actor()));
    }

    public function test_digest_changes_for_each_material_warning_input_and_actor(): void
    {
        $service = app(ScheduleFeasibilityService::class);
        $result = $this->warningResult();
        $version = $service->confirmationVersion($result, $this->actor());
        $changes = [
            'policy_version' => 'hybrid-travel-v2', 'schedule_version' => 'neighbor-changed',
            'location.address_hash' => 'other-property', 'location.building_key' => 'other-building',
            'visits.0.start' => '2026-10-02T13:05:00+00:00', 'visits.0.duration_minutes' => 30,
            'visits.0.photographer_id' => 22, 'visits.0.timezone' => 'America/Chicago',
            'transitions.0.source' => 'mileage_band', 'transitions.0.required_minutes' => 30,
            'transitions.0.available_minutes' => 10, 'transitions.0.reason_code' => 'routes_unavailable',
            'transitions.0.review_required' => true, 'transitions.0.drive_minutes' => 18.1,
            'transitions.0.candidate_end' => '2026-10-02T13:20:00+00:00',
            'transitions.0.neighbor.services.0.name' => 'Interior Photos',
        ];
        foreach ($changes as $key => $value) {
            $changed = $result;
            data_set($changed, $key, $value);
            $this->assertNotSame($version, $service->confirmationVersion($changed, $this->actor()), $key);
        }
        $this->assertNotSame($version, $service->confirmationVersion($result, $this->actor(11)));
    }

    public function test_missing_or_stale_version_returns_fresh_public_warning(): void
    {
        $service = app(ScheduleFeasibilityService::class);
        $result = $this->warningResult();
        $expected = $service->confirmationVersion($result, $this->actor());
        foreach ([null, '', str_repeat('0', 64)] as $submitted) {
            try {
                $service->assertResult($result, $this->confirmationPayload($submitted), null, $this->actor());
                $this->fail('A deliberate swipe must acknowledge the current warning.');
            } catch (PublicApiResponseException $exception) {
                $this->assertSame(422, $exception->getResponse()->getStatusCode());
                $body = $exception->getResponse()->getData(true);
                $this->assertArrayHasKey('travel_override_confirmation_version', $body['errors']);
                $this->assertSame($expected, $body['feasibility']['confirmation_version']);
                $this->assertArrayNotHasKey('location', $body['feasibility']);
                $this->assertArrayNotHasKey('from_visit_id', $body['feasibility']['transitions'][0]);
            }
        }
    }

    public function test_current_version_succeeds_but_copied_version_cannot_mask_new_route(): void
    {
        $service = app(ScheduleFeasibilityService::class);
        $result = $this->warningResult();
        $result['confirmation_version'] = $service->confirmationVersion($result, $this->actor());
        $payload = $this->confirmationPayload($result['confirmation_version']);
        $service->assertResult($result, $payload, null, $this->actor());
        $result['transitions'][0]['source'] = 'mileage_band';
        $this->expectException(PublicApiResponseException::class);
        $service->assertResult($result, $payload, null, $this->actor());
    }
}
