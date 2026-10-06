<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\ManualNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualNotificationAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_excludes_superseded_primary_and_deduplicates_service_assignees(): void
    {
        $original = User::factory()->photographer()->create();
        $assigned = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create(['photographer_id' => $original->id]);
        foreach (Service::factory()->count(2)->create() as $service) {
            $shoot->services()->attach($service->id, ['photographer_id' => $assigned->id]);
        }
        $this->assertSame([$assigned->id], array_column(app(ManualNotificationService::class)->listRecipients($shoot, 'photographer'), 'id'));

        // Preview and both send channels use this same resolver; a stale selected ID is rejected.
        foreach (['email', 'sms'] as $channel) {
            try {
                app(ManualNotificationService::class)->preview($shoot, 'shoot_updated', 'photographer', $channel, $original->id);
                $this->fail('Superseded primary must not be a valid recipient.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('has no photographer to notify', $exception->getMessage());
            }
        }

        $shoot->services()->attach(Service::factory()->create()->id, ['photographer_id' => null]);
        $shoot->unsetRelation('services');
        $this->assertSame([$original->id, $assigned->id], array_column(app(ManualNotificationService::class)->listRecipients($shoot, 'photographer'), 'id'));
    }
}
