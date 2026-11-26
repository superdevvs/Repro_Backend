<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ReproAi\Flows\ManageBookingFlow;
use App\Services\ReproAi\ShootService;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\Scheduling\ScheduleCommitGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class RobbieSchedulingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['availability.hybrid_travel_enabled' => false]);
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
    }

    private function actor(string $role, array $secondary = [], array $denied = []): User
    {
        return User::factory()->create(['role' => $role, 'secondary_roles' => $secondary,
            'email_verification_required_at' => null, 'permission_overrides' => ['allow' => [], 'deny' => $denied]]);
    }

    private function shoot(): Shoot
    {
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled',
            'photographer_id' => null, 'timezone' => 'UTC', 'scheduled_at' => '2031-10-09 10:00:00',
            'scheduled_date' => '2031-10-09', 'time' => '10:00']);
        $service = Service::factory()->noIntake()->create(['photographer_required' => false]);
        $shoot->services()->attach($service->id, ['quantity' => 1, 'price' => 100, 'duration_minutes' => 0,
            'scheduled_at' => '2031-10-09 10:00:00']);

        return $shoot->fresh();
    }

    public static function deniedOfficeActors(): array
    {
        $cases = [];
        foreach ([['admin', []], ['editing_manager', []], ['salesRep', []],
            ['admin', ['salesRep']], ['editor', ['admin']], ['photographer', ['sales_rep']]] as [$role, $secondary]) {
            foreach (['shoots-update', 'shoots-manage'] as $denial) $cases[] = [$role, $secondary, $denial];
        }
        return $cases;
    }

    #[DataProvider('deniedOfficeActors')]
    public function test_direct_conversation_service_rejects_denied_staff_before_travel_or_writes(string $role, array $secondary, string $denial): void
    {
        $actor = $this->actor($role, $secondary, [$denial]);
        $shoot = $this->shoot();
        $before = $shoot->getRawOriginal();
        $lineBefore = $shoot->serviceItems()->sole()->getRawOriginal();
        $guard = $this->mock(ScheduleCommitGuard::class);
        $guard->shouldNotReceive('enabled', 'prepare', 'commit');
        try {
            app(ShootService::class)->updateFromAiConversation($shoot, ['date' => '2031-10-10', 'time_window' => '11:00'], $actor);
            $this->fail('Denied staff must not move an appointment through Robbie.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(ShootService::RESCHEDULE_DENIED, $exception->getMessage());
        }
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertSame($lineBefore, $shoot->serviceItems()->sole()->getRawOriginal());
        $this->assertDatabaseCount('shoot_reschedule_requests', 0);
        Http::assertNothingSent(); Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_direct_service_uses_explicit_client_actor_and_does_not_borrow_authenticated_admin_rights(): void
    {
        $shoot = $this->shoot();
        $client = $shoot->client;
        $this->actingAs($this->actor('admin'));
        $before = $shoot->getRawOriginal();
        try {
            app(ShootService::class)->updateFromAiConversation($shoot, ['date' => '2031-10-10', 'time_window' => '11:00'], $client);
            $this->fail('A client must use the existing pending-request workflow.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(ShootService::RESCHEDULE_REQUEST_GUIDANCE, $exception->getMessage());
        }
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertDatabaseCount('shoot_reschedule_requests', 0);
    }

    public function test_conversation_guides_only_request_eligible_clients_and_clears_denied_staff_drafts(): void
    {
        $shoot = $this->shoot();
        $before = $shoot->getRawOriginal();
        foreach ([[$shoot->client, 'reschedule_request_required'],
            [$this->actor('admin', [], ['shoots-update']), 'forbidden']] as [$actor, $code]) {
            $session = AiChatSession::create(['user_id' => $actor->id, 'title' => 'Scheduling permission regression',
                'step' => 'reschedule', 'intent' => 'manage_booking',
                'state_data' => ['shoot_id' => $shoot->id, 'new_date' => '2031-10-10', 'new_time' => '11:00']]);
            $result = app(ManageBookingFlow::class)->handle($session, 'Confirm');
            $this->assertSame($code, $result['assistant_messages'][0]['metadata']['code']);
            $this->assertSame($code === 'forbidden' ? ShootService::RESCHEDULE_DENIED : ShootService::RESCHEDULE_REQUEST_GUIDANCE,
                $result['assistant_messages'][0]['content']);
            $this->assertNull($session->fresh()->step);
            $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('shoot_reschedule_requests', 0);
    }

    public function test_llm_tool_identity_cannot_be_replaced_by_context_and_clients_cannot_move_directly(): void
    {
        $shoot = $this->shoot();
        $admin = $this->actor('admin');
        $before = $shoot->getRawOriginal();
        foreach ([[$shoot->client, 'reschedule_request_required'],
            [$this->actor('admin', [], ['shoots-update']), 'forbidden']] as [$actor, $code]) {
            $this->actingAs($actor);
            $result = app(ToolDispatcher::class)->dispatch('reschedule_shoot', ['shoot_id' => $shoot->id, 'new_time' => '11:00'],
                ['user_id' => $admin->id, 'user_role' => 'superadmin']);
            $this->assertFalse($result['success']);
            $this->assertSame($code, $result['code']);
            $this->assertSame(403, $result['status_code']);
            $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        }
    }

    public static function permittedOfficeActors(): array
    {
        return [['admin', []], ['editing_manager', []], ['salesRep', []], ['admin', ['salesRep']], ['editor', ['admin']]];
    }

    #[DataProvider('permittedOfficeActors')]
    public function test_authorized_explicit_staff_actor_moves_booking_without_changing_its_client(string $role, array $secondary): void
    {
        $shoot = $this->shoot();
        $ownerId = $shoot->client_id;
        $this->actingAs($shoot->client);
        $saved = app(ShootService::class)->updateFromAiConversation($shoot, ['date' => '2031-10-10', 'time_window' => '11:00'],
            $this->actor($role, $secondary));
        $this->assertSame('2031-10-10', $saved->scheduled_date->toDateString());
        $this->assertSame($ownerId, $saved->client_id);
        Mail::assertNothingSent(); Notification::assertNothingSent(); Http::assertNothingSent();
    }

    public function test_llm_time_only_change_preserves_property_local_date_and_uses_guarded_schedule_service(): void
    {
        $shoot = $this->shoot();
        $shoot->update(['timezone' => 'America/Los_Angeles', 'scheduled_at' => '2031-10-10 01:00:00',
            'scheduled_date' => '2031-10-09', 'time' => '18:00']);
        $shoot->serviceItems()->sole()->update(['scheduled_at' => '2031-10-10 01:00:00']);
        $this->actingAs($this->actor('admin'));
        $result = app(ToolDispatcher::class)->dispatch('reschedule_shoot', ['shoot_id' => $shoot->id, 'new_time' => '19:00']);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('2031-10-09', $shoot->fresh()->scheduled_date->toDateString());
        $this->assertSame('2031-10-10 02:00:00', \Carbon\CarbonImmutable::parse($shoot->fresh()->getRawOriginal('scheduled_at'), 'UTC')->format('Y-m-d H:i:s'));
    }

    public function test_denied_service_edit_and_booking_status_tools_cannot_mutate_rows(): void
    {
        $shoot = $this->shoot();
        $before = $shoot->getRawOriginal();
        $actor = $this->actor('admin', [], ['shoots-update', 'shoots-manage']);
        try {
            app(ShootService::class)->updateFromAiConversation($shoot, ['service_ids' => [$shoot->serviceItems()->sole()->service_id]], $actor);
            $this->fail('Denied service edits must stop at the service boundary.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->actingAs($actor);
        foreach (['scheduled', 'on_hold', 'cancelled'] as $status) {
            $result = app(ToolDispatcher::class)->dispatch('update_shoot_status', ['shoot_id' => $shoot->id, 'status' => $status]);
            $this->assertFalse($result['success']);
            $this->assertSame(403, $result['status_code']);
        }
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent(); Http::assertNothingSent();
    }
}
