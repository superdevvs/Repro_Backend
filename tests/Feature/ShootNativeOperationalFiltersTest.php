<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShootNativeOperationalFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_status_aliases_and_workflow_precedence_filter_before_pagination(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $matches = collect();
        foreach (['review', 'pending_review', 'ready_for_review', 'editing_complete', 'qc'] as $alias) {
            $matches->push($this->shoot(['status' => 'scheduled', 'workflow_status' => $alias]));
        }
        for ($i = 0; $i < 8; $i++) {
            $matches->push($this->shoot(['workflow_status' => ' REVIEW ']));
        }
        // A stale legacy status must not leak a delivered shoot into Review.
        $this->shoot(['status' => 'review', 'workflow_status' => 'delivered']);
        $fallback = $this->shoot(['status' => 'scheduled', 'workflow_status' => '']);
        $this->shoot(['workflow_status' => 'editing']);

        $params = ['tab' => 'all', 'per_page' => 9, 'statuses' => ['review']];
        $first = $this->listing($params)->assertOk()->assertJsonPath('meta.count', 13);
        $second = $this->listing($params + ['page' => 2])->assertOk()->assertJsonPath('meta.count', 13);
        $this->assertCount(9, $first->json('data'));
        $this->assertEqualsCanonicalizing($matches->pluck('id')->all(), array_merge(
            array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')
        ));
        $combined = $this->listing(['tab' => 'all', 'statuses' => ['review', 'scheduled']])->assertOk();
        $this->assertEqualsCanonicalizing($matches->pluck('id')->push($fallback->id)->all(), array_column($combined->json('data'), 'id'));
        Http::assertNothingSent();
    }

    public function test_assignment_and_priority_filters_have_distinct_cache_entries_and_main_photographer_semantics(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create();
        $unassignedFlagged = $this->shoot(['photographer_id' => null, 'is_flagged' => true]);
        // The web filter is absence of the main photographer, even if a service
        // already has someone. The assignment editor handles those individually.
        $unassignedFlagged->services()->attach($service->id, ['photographer_id' => $photographer->id]);
        $unassigned = $this->shoot(['photographer_id' => null, 'is_flagged' => false]);
        $assignedFlagged = $this->shoot(['photographer_id' => $photographer->id, 'is_flagged' => true]);
        $assignedFlagged->services()->attach($service->id, ['photographer_id' => null]);
        $this->shoot(['is_flagged' => false]);

        $this->listing(['tab' => 'all'])->assertOk()->assertJsonPath('meta.count', 4);
        $this->assertIds([$unassignedFlagged->id, $unassigned->id], ['unassigned_only' => 'true']);
        $this->assertIds([$unassignedFlagged->id, $assignedFlagged->id], ['flagged_only' => '1']);
        $this->assertIds([$unassignedFlagged->id], ['unassigned_only' => '1', 'flagged_only' => 'true']);
        $this->listing(['tab' => 'all', 'unassigned_only' => 'false', 'flagged_only' => '0'])->assertOk()->assertJsonPath('meta.count', 4);
    }

    public function test_status_priority_and_assignment_combine_with_existing_search_people_services_payment_and_dates(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'salesRep']));
        $client = User::factory()->create(['role' => 'client']);
        $rep = User::factory()->create(['role' => 'salesRep']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create(['name' => 'HDR Photos']);
        $attributes = ['client_id' => $client->id, 'rep_id' => $rep->id, 'photographer_id' => null,
            'scheduled_date' => '2026-11-03', 'address' => '15 Native Filter Lane', 'is_flagged' => true,
            'payment_status' => 'unpaid', 'total_quote' => 200];
        $match = $this->shoot($attributes);
        $match->services()->attach($service->id, ['photographer_id' => $photographer->id]);
        foreach ([['is_flagged' => false], ['scheduled_date' => '2026-11-04'], ['workflow_status' => 'delivered'],
            ['payment_status' => 'paid'], ['address' => '99 Other Street'], ['photographer_id' => $photographer->id]] as $change) {
            $this->shoot(array_replace($attributes, $change))->services()->attach($service->id, ['photographer_id' => $photographer->id]);
        }
        $this->assertIds([$match->id], [
            'statuses' => ['scheduled'], 'unassigned_only' => 'true', 'flagged_only' => 'true',
            'client_id' => [$client->id], 'sales_rep_id' => [$rep->id], 'photographer_id' => [$photographer->id],
            'services' => ['HDR Photos'], 'payment_status' => 'unpaid', 'search' => 'Native Filter Lane',
            'scheduled_start' => '2026-11-03', 'scheduled_end' => '2026-11-03',
        ]);
    }

    public function test_new_filters_cannot_expand_client_editor_or_photographer_access(): void
    {
        foreach (['client', 'editor', 'photographer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $assignment = ['client' => 'client_id', 'editor' => 'editor_id', 'photographer' => 'photographer_id'][$role];
            $mine = $this->shoot([$assignment => $user->id, 'workflow_status' => 'editing', 'is_flagged' => true]);
            $this->shoot(['workflow_status' => 'editing', 'is_flagged' => true]);
            $this->actingAs($user);
            $params = ['statuses' => ['editing']];
            if ($role !== 'client') {
                $params['flagged_only'] = '1';
            }
            $this->assertIds([$mine->id], $params);
            $this->listing(['tab' => 'all', 'unassigned_only' => 'true'])->assertForbidden();
            if ($role === 'client') {
                $this->listing(['tab' => 'all', 'flagged_only' => 'true'])->assertForbidden();
            }
        }
    }

    public function test_filter_metadata_advertises_canonical_statuses_and_role_capabilities(): void
    {
        foreach (['admin', 'superadmin', 'editing_manager', 'salesRep', 'sales_rep', 'editor', 'photographer', 'client'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($user)->getJson('/api/shoots/filters')->assertOk();
            $values = array_column($response->json('data.statuses'), 'value');
            $this->assertCount(13, $values);
            $this->assertContains('review', $values);
            $this->assertNotContains('pending_review', $values);
            $this->assertSame(! in_array($role, ['editor', 'photographer', 'client'], true), $response->json('data.filter_capabilities.assignments'));
            $this->assertSame($role !== 'client', $response->json('data.filter_capabilities.priority'));
            $response->assertJsonStructure(['data' => ['clients', 'photographers', 'services', 'salesReps', 'statuses' => [['value', 'label']]]]);
        }
    }

    public function test_invalid_filter_shapes_return_validation_errors_instead_of_silent_filters_or_server_errors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['statuses' => 'scheduled'], ['statuses' => ['unrecognized']], ['statuses' => [['review']]],
            ['unassigned_only' => 'yes'], ['flagged_only' => 'anything'], ['flagged_only' => ['1']]] as $invalid) {
            $this->listing(['tab' => 'all'] + $invalid)->assertUnprocessable();
        }
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_replace([
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'is_private_listing' => false, 'is_flagged' => false,
        ], $attributes));
    }

    private function listing(array $parameters)
    {
        return $this->getJson('/api/shoots?'.http_build_query($parameters + ['include_files' => 'false', 'view' => 'card']));
    }

    private function assertIds(array $expected, array $parameters): void
    {
        $response = $this->listing($parameters + ['tab' => 'all'])->assertOk()->assertJsonPath('meta.count', count($expected));
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('data'), 'id'));
    }
}
