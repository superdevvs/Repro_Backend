<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateVariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ShootEmailImageRequirementsTest extends TestCase
{
    use RefreshDatabase;

    public static function emailFlows(): array
    {
        return [['confirmation'], ['reminder']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emailFlows')]
    public function test_counts_are_current_scoped_and_do_not_expose_raw_requirements_to_clients(string $flow): void
    {
        [$shoot, $client, $first, $second] = $this->fixture();
        $deliveries = $this->captureEmails();
        $mail = app(MailService::class);
        $sent = $flow === 'confirmation'
            ? $mail->sendShootScheduledEmail($client, $shoot, '', shouldNotifyPhotographer: true)
            : $mail->sendShootReminderEmail($client, $shoot, shouldNotifyPhotographer: true);
        $this->assertTrue($sent);
        $messages = collect($deliveries->all())->keyBy('to');
        if ($output = getenv('EMAIL_COUNT_QA_OUTPUT_DIR')) {
            foreach (['client' => $client, 'photographer' => $first] as $role => $recipient) {
                file_put_contents($output.'/'.$flow.'-'.$role.'.html', $messages[$recipient->email]['body_html']);
            }
        }
        foreach (['body_html', 'body_text'] as $field) {
            $clientBody = $this->visible($messages[$client->email][$field]);
            $this->assertStringContainsString('35 final photos', $clientBody);
            $this->assertStringContainsString('10 final photos', $clientBody);
            $this->assertStringNotContainsString('raw images required', $clientBody);
            $firstBody = $this->visible($messages[$first->email][$field]);
            $this->assertStringContainsString('35 final photos · 105 raw images required', $firstBody);
            $this->assertStringNotContainsString('Aerial Photos', $firstBody);
            $secondBody = $this->visible($messages[$second->email][$field]);
            $this->assertStringContainsString('10 final photos · 10 raw images required', $secondBody);
            $this->assertStringNotContainsString('HDR Photos', $secondBody);
            $this->assertStringNotContainsString('999 final photos', $secondBody);
        }
    }

    public function test_staff_confirmation_and_template_variables_include_requirements_for_multiple_services(): void
    {
        [$shoot] = $this->fixture();
        $admin = User::factory()->create(['role' => 'editing_manager']);
        $deliveries = $this->captureEmails();
        $this->assertTrue(app(MailService::class)->sendShootRequestedStaffEmail($admin, $shoot));
        foreach (['body_html', 'body_text'] as $field) {
            $body = $this->visible($deliveries->first()[$field]);
            $this->assertStringContainsString('35 final photos · 105 raw images required', $body);
            $this->assertStringContainsString('10 final photos · 10 raw images required', $body);
        }
        foreach (['admin', 'editing_manager', 'rep', 'photographer', 'client'] as $role) {
            $vars = app(TemplateVariableResolver::class)->resolve(['shoot' => $shoot->fresh(), 'recipient_type' => $role, 'channel' => 'email']);
            foreach (['services_provided', 'services_provided_html'] as $key) {
                $body = $this->visible($vars[$key]);
                $this->assertStringContainsString('35 final photos', $body);
                $this->assertSame($role !== 'client', str_contains($body, '105 raw images required'));
                $this->assertStringNotContainsString('999 final photos', $body);
            }
        }
        $sms = app(TemplateVariableResolver::class)->resolve(['shoot' => $shoot->fresh(), 'recipient_type' => 'photographer', 'channel' => 'sms']);
        $this->assertStringNotContainsString('raw images required', $sms['services_provided']);
    }

    public function test_unconfigured_photo_targets_are_not_invented_from_quantity(): void
    {
        [$shoot, $client] = $this->fixture();
        $service = Service::factory()->create(['name' => 'Custom Photo Coverage', 'photo_count' => null, 'upload_intake_type' => 'photo', 'uses_hdr_brackets' => true]);
        $shoot->services()->attach($service->id, ['quantity' => 7, 'price' => 20]);
        $deliveries = $this->captureEmails();
        $this->assertTrue(app(MailService::class)->sendShootScheduledEmail($client, $shoot, '', shouldNotifyPhotographer: false));
        $body = $this->visible($deliveries->first()['body_text']);
        $this->assertStringContainsString('Final photo count not set', $body);
        $this->assertStringNotContainsString('7 final photos', $body);
    }

    private function fixture(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $first = User::factory()->photographer()->create();
        $second = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'photographer_id' => $first->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled', 'scheduled_at' => now()->addDays(2), 'bracket_mode' => 5]);
        $hdr = Service::factory()->create(['name' => 'HDR Photos', 'photo_count' => 99, 'upload_intake_type' => 'photo', 'uses_hdr_brackets' => true]);
        $aerial = Service::factory()->create(['name' => 'Aerial Photos', 'photo_count' => 10, 'upload_intake_type' => 'photo', 'uses_hdr_brackets' => false]);
        $video = Service::factory()->create(['name' => 'Property Video', 'photo_count' => 999, 'upload_intake_type' => 'video', 'uses_hdr_brackets' => true]);
        $shoot->services()->attach($hdr->id, ['quantity' => 1, 'price' => 275, 'contracted_photo_count' => 35, 'bracket_mode' => 3, 'photographer_id' => $first->id]);
        $shoot->services()->attach($aerial->id, ['quantity' => 1, 'price' => 75, 'photographer_id' => $second->id]);
        $shoot->services()->attach($video->id, ['quantity' => 1, 'price' => 125, 'photographer_id' => $second->id]);

        return [$shoot, $client, $first, $second];
    }

    private function captureEmails(): \Illuminate\Support\Collection
    {
        $deliveries = collect();
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->andReturnUsing(function (array $payload) use ($deliveries) {
            $deliveries->push($payload);
            return new Message(['status' => 'SENT']);
        });
        $this->app->instance(MessagingService::class, $messaging);
        return $deliveries;
    }

    private function visible(string $body): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
