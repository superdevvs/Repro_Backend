<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\ShootUnit;
use App\Models\ShootUploadAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootUploadSummaryTest extends TestCase
{
    use RefreshDatabase;

    public static function staffRoles(): array
    {
        return array_map(fn ($role) => [$role], ['admin', 'superadmin', 'editing_manager', 'photographer', 'salesRep']);
    }

    private function attempt(Shoot $shoot, User $actor, string $batch, int $slot, int $total, string $status = 'completed'): ShootUploadAttempt
    {
        return ShootUploadAttempt::create([
            'shoot_id' => $shoot->id, 'actor_id' => $actor->id, 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64), 'correlation_id' => (string) Str::uuid(),
            'upload_type' => 'raw', 'upload_batch_id' => $batch, 'upload_batch_index' => $slot,
            'upload_batch_total' => $total, 'status' => $status, 'completed_at' => now(),
            'result_file_ids' => $status === 'completed' ? [$slot + 100] : [],
            'result_payload' => ['uploaded_files' => [['id' => $slot + 100, 'file_size' => 1000]], 'error_count' => 0],
        ]);
    }

    #[DataProvider('staffRoles')]
    public function test_staff_can_read_only_confirmed_batches(string $role): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $shoot = Shoot::factory()->create(['photographer_id' => $actor->id]);
        Sanctum::actingAs($actor);
        $this->attempt($shoot, $actor, 'complete', 0, 2);
        $this->travel(5)->seconds();
        $last = $this->attempt($shoot, $actor, 'complete', 1, 2);
        $this->attempt($shoot, $actor, 'pending', 0, 2);
        $this->attempt($shoot, $actor, 'pending', 1, 2, 'pending');
        $this->attempt($shoot, $actor, 'failed', 0, 1, 'failed');
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.fileCount', 2)
            ->assertJsonPath('data.0.totalBytes', 2000)
            ->assertJsonPath('data.0.completedAt', $last->completed_at->toISOString());
    }

    public function test_clients_and_unassigned_photographers_cannot_read_summaries(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer']));
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries')->assertForbidden();
    }

    public function test_new_batch_is_separate_and_partial_results_are_not_success(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($actor);
        $this->attempt($shoot, $actor, 'first', 0, 1);
        $this->travel(2)->seconds();
        $this->attempt($shoot, $actor, 'second', 0, 1);
        $partial = $this->attempt($shoot, $actor, 'partial', 0, 1);
        $partial->update(['result_payload' => ['error_count' => 1, 'uploaded_files' => [['id' => 200]]]]);
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $actor->id.':raw:second');
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries?unit_id=999999')->assertNotFound();
    }

    public function test_summaries_follow_unit_and_photographer_file_scope(): void
    {
        $actor = User::factory()->create(['role' => 'photographer']);
        $other = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $actor->id]);
        $units = collect(['A', 'B'])->map(fn ($label) => ShootUnit::create(['shoot_id' => $shoot->id, 'client_key' => (string) Str::uuid(), 'label' => $label, 'kind' => 'unit']));
        foreach ([$actor, $other] as $index => $photographer) {
            $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => Service::factory()->create()->id,
                'photographer_id' => $photographer->id, 'shoot_unit_id' => $units[$index]->id, 'price' => 1, 'quantity' => 1]);
            $this->attempt($shoot, $photographer, 'unit-'.$index, 0, 1)->update(['shoot_service_id' => $item->id]);
        }
        Sanctum::actingAs($actor);
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries?unit_id='.$units[1]->id)->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries?unit_id='.$units[1]->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/shoots/'.$shoot->id.'/upload-summaries?unit_id=property')->assertOk()->assertJsonCount(0, 'data');
    }
}
