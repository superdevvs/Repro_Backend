<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateVariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ServiceScheduleEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_reschedule_reaches_automated_client_and_photographer_emails(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->photographer()->create();
        $service = Service::factory()->create(['name' => 'Amenities Photos']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'scheduled_date' => '2026-09-14', 'scheduled_at' => '2026-09-14 09:00:00',
            'time' => '09:00', 'status' => 'scheduled', 'workflow_status' => 'scheduled',
        ]);
        $shoot->services()->attach($service->id, [
            'scheduled_at' => '2026-09-14 09:00:00', 'photographer_id' => $photographer->id,
            'price' => 100, 'quantity' => 1,
        ]);
        $before = app(MailService::class)->captureShootSnapshot($shoot);
        $shoot->services()->updateExistingPivot($service->id, ['scheduled_at' => '2026-09-15 11:00:00']);
        $shoot->refresh();
        $changes = app(MailService::class)->buildShootChangeSummary($before, $shoot);
        $this->assertStringContainsString('Sep 15, 2026', $changes['summary']);

        $deliveries = [];
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->twice()->andReturnUsing(function (array $payload) use (&$deliveries) {
            $deliveries[$payload['to']] = $payload;

            return new Message;
        });
        $this->app->instance(MessagingService::class, $messaging);
        $dispatch = new \ReflectionMethod(AutomationWorkflowExecutor::class, 'dispatchProtectedTrigger');
        $dispatch->invoke(app(AutomationWorkflowExecutor::class), 'SHOOT_UPDATED', ['client', 'photographer'], [
            'shoot' => $shoot, 'client' => $client, 'shoot_changes' => $changes['summary'],
        ]);
        foreach ([$client->email, $photographer->email] as $email) {
            $body = html_entity_decode(strip_tags($deliveries[$email]['body_html']));
            $this->assertStringContainsString('Sep 15, 2026', $body);
            $this->assertStringContainsString('11:00 AM', $body);
            $this->assertStringNotContainsString('Please review updated details in the dashboard.', $body);
        }
        $variables = app(TemplateVariableResolver::class)->resolve(['shoot' => $shoot]);
        $this->assertSame('Sep 15, 2026', $variables['shoot_date']);
        $this->assertSame('11:00 AM', $variables['shoot_time']);
        $this->assertStringContainsString('Sep 15, 2026 at 11:00 AM', $variables['services_provided_html']);
    }

    public function test_multiple_service_dates_are_explicit_and_photographer_schedule_is_scoped(): void
    {
        $photographer = User::factory()->photographer()->create();
        $other = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);
        foreach ([[$photographer, '2026-09-15 09:00:00'], [$other, '2026-09-16 14:00:00']] as [$person, $appointment]) {
            $service = Service::factory()->create();
            $shoot->services()->attach($service->id, ['photographer_id' => $person->id, 'scheduled_at' => $appointment, 'price' => 100, 'quantity' => 1]);
        }
        $variables = app(TemplateVariableResolver::class)->resolve(['shoot' => $shoot]);
        $this->assertSame('Multiple dates — see service schedules', $variables['shoot_date']);
        $this->assertSame('See service schedules', $variables['shoot_time']);
        $this->assertStringContainsString('Sep 15, 2026 at 9:00 AM', $variables['services_provided']);
        $this->assertStringContainsString('Sep 16, 2026 at 2:00 PM', $variables['services_provided']);
        $format = new \ReflectionMethod(MailService::class, 'formatShootData');
        $data = $format->invoke(app(MailService::class), $shoot, $photographer, 'photographer');
        $this->assertSame('Sep 15, 2026', $data->date);
        $this->assertSame('9:00 AM', $data->time);
        $this->assertCount(1, $data->services);
    }
}
