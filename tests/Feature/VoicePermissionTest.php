<?php

namespace Tests\Feature;

use App\Models\ScheduledVoiceCall;
use App\Models\Setting;
use App\Models\SmsNumber;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceScheduleOverride;
use App\Services\RolePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoicePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_only_user_can_read_calls_but_cannot_mutate_any_voice_resource(): void
    {
        $viewer = User::factory()->photographer()->create([
            'permission_overrides' => ['allow' => ['voice-calls-view'], 'deny' => []],
        ]);
        Sanctum::actingAs($viewer);
        [$call, $scheduled, $number, $override] = $this->voiceFixtures();

        foreach (['calls', "calls/{$call->id}", "calls/{$call->id}/transcript", "calls/{$call->id}/recording-url", 'scheduled-calls', 'schedule/overrides', 'numbers', 'settings'] as $path) {
            $this->getJson('/api/voice/'.$path)->assertOk();
        }

        $this->assertMutationsForbidden($call, $scheduled, $number, $override);
        $this->assertSame('Original recap', $call->fresh()->summary);
        $this->assertNull($call->fresh()->metadata);
        $this->assertSame('scheduled', $scheduled->fresh()->status);
        $this->assertDatabaseHas('voice_schedule_overrides', ['id' => $override->id]);
    }

    public function test_admin_per_user_denials_remain_effective_after_permission_migration(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create([
            'permission_overrides' => [
                'allow' => ['voice-calls-operate', 'voice-calls-manage'],
                'deny' => ['voice-calls-operate', 'voice-calls-manage'],
            ],
        ]));

        $permissions = $this->getJson('/api/me/permissions')->assertOk()->json('permissionIds');
        $this->assertContains('voice-calls-view', $permissions);
        $this->assertNotContains('voice-calls-operate', $permissions);
        $this->assertNotContains('voice-calls-manage', $permissions);
        $this->assertMutationsForbidden(...$this->voiceFixtures());
    }

    public function test_operator_can_save_notes_and_cancel_callbacks_but_cannot_change_voice_configuration(): void
    {
        Sanctum::actingAs(User::factory()->photographer()->create([
            'permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-operate'], 'deny' => []],
        ]));
        [$call, $scheduled, $number, $override] = $this->voiceFixtures();

        $this->postJson("/api/voice/calls/{$call->id}/note", ['body' => 'Client confirmed access.'])
            ->assertOk()->assertJsonPath('metadata.notes.0.body', 'Client confirmed access.');
        $this->postJson("/api/voice/scheduled-calls/{$scheduled->id}/cancel")
            ->assertOk()->assertJsonPath('status', 'cancelled');
        foreach ($this->managementRoutes($number, $override) as [$method, $path]) {
            $this->json($method, '/api/voice/'.$path, [])->assertForbidden()->assertJsonPath('action', 'manage');
        }
    }

    public function test_manager_can_change_business_hours_but_manage_does_not_grant_call_operation(): void
    {
        Sanctum::actingAs(User::factory()->photographer()->create([
            'permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-manage'], 'deny' => []],
        ]));
        [$call] = $this->voiceFixtures();

        $this->postJson('/api/voice/schedule/overrides', [
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'mode' => 'closed',
            'label' => 'Team meeting',
        ])->assertCreated()->assertJsonPath('label', 'Team meeting');
        $this->postJson("/api/voice/calls/{$call->id}/note", ['body' => 'Unauthorized note'])
            ->assertForbidden()->assertJsonPath('action', 'operate');
        $this->postJson('/api/voice/calls/outbound', ['to' => '+12025550124'])
            ->assertForbidden()->assertJsonPath('action', 'operate');
    }

    public function test_operation_permission_alone_does_not_bypass_workspace_access(): void
    {
        Sanctum::actingAs(User::factory()->photographer()->create([
            'permission_overrides' => ['allow' => ['voice-calls-operate'], 'deny' => []],
        ]));
        [$call] = $this->voiceFixtures();

        $this->postJson("/api/voice/calls/{$call->id}/note", ['body' => 'Unauthorized note'])
            ->assertForbidden()->assertJsonPath('action', 'view');
    }

    public function test_legacy_admin_permissions_gain_voice_actions_without_granting_them_to_other_roles(): void
    {
        Setting::query()->updateOrCreate(['key' => 'permissions.role_map.v1'], [
            'value' => json_encode(['version' => 1, 'roles' => [
                'admin' => ['dashboard-view', 'voice-calls-view'],
                'photographer' => ['voice-calls-view'],
            ]]),
            'type' => 'json',
        ]);
        $service = app(RolePermissionService::class);
        $admin = User::factory()->admin()->create();
        $superadmin = User::factory()->superAdmin()->create();
        $viewer = User::factory()->photographer()->create();

        foreach (['view', 'operate', 'manage', 'supervise'] as $action) {
            $this->assertTrue($service->userCan($admin, 'voice-calls', $action));
            $this->assertTrue($service->userCan($superadmin, 'voice-calls', $action));
        }
        $this->assertTrue($service->userCan($viewer, 'voice-calls', 'view'));
        $this->assertFalse($service->userCan($viewer, 'voice-calls', 'operate'));
        $this->assertFalse($service->userCan($viewer, 'voice-calls', 'manage'));
        $this->assertFalse($service->userCan($viewer, 'voice-calls', 'supervise'));
        $stored = json_decode(Setting::query()->where('key', 'permissions.role_map.v1')->value('value'), true);
        $this->assertContains('voice-calls-operate', $stored['roles']['admin']);
        $this->assertContains('voice-calls-manage', $stored['roles']['admin']);
        $this->assertContains('voice-calls-supervise', $stored['roles']['admin']);
    }

    public function test_supervision_is_independent_of_operation_and_a_user_denial_wins(): void
    {
        $service = app(RolePermissionService::class);
        $operator = User::factory()->photographer()->create(['permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-operate'], 'deny' => []]]);
        $admin = User::factory()->admin()->create(['permission_overrides' => ['allow' => [], 'deny' => ['voice-calls-supervise']]]);
        $this->assertTrue($service->userCan($operator, 'voice-calls', 'operate'));
        $this->assertFalse($service->userCan($operator, 'voice-calls', 'supervise'));
        $this->assertFalse($service->userCan($admin, 'voice-calls', 'supervise'));
    }

    public function test_call_operator_without_sms_permission_cannot_send_wrap_up_sms_or_save_side_effects(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->photographer()->create([
            'permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-operate'], 'deny' => []],
        ]));
        [$call] = $this->voiceFixtures();

        $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
            'recap_body' => 'Must not be saved',
            'send_sms' => true,
            'sms_body' => 'Must not be sent',
            'create_task' => true,
            'task_title' => 'Must not be created',
        ])->assertForbidden();

        $this->assertSame('Original recap', $call->fresh()->summary);
        $this->assertNull($call->fresh()->metadata);
        $this->assertDatabaseCount('voice_follow_up_tasks', 0);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    private function voiceFixtures(): array
    {
        return [
            VoiceCall::query()->create([
                'direction' => 'INBOUND', 'status' => 'completed',
                'from_phone' => '+12025550124', 'to_phone' => '+12025550100',
                'summary' => 'Original recap',
            ]),
            ScheduledVoiceCall::query()->create(['target_phone' => '+12025550124', 'status' => 'scheduled']),
            SmsNumber::query()->create(['phone_number' => '+12025550100', 'label' => 'Main line']),
            VoiceScheduleOverride::query()->create([
                'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'mode' => 'closed',
            ]),
        ];
    }

    private function assertMutationsForbidden(VoiceCall $call, ScheduledVoiceCall $scheduled, SmsNumber $number, VoiceScheduleOverride $override): void
    {
        Http::fake();
        $operationalRoutes = [
            ['POST', 'calls/outbound'],
            ['POST', "calls/{$call->id}/hangup"],
            ['POST', "calls/{$call->id}/page-staff"],
            ['POST', "calls/{$call->id}/transfer"],
            ['POST', "calls/{$call->id}/cockpit-opened"],
            ['POST', "calls/{$call->id}/note"],
            ['PATCH', "calls/{$call->id}/wrap-up"],
            ['POST', 'scheduled-calls'],
            ['PATCH', "scheduled-calls/{$scheduled->id}"],
            ['POST', "scheduled-calls/{$scheduled->id}/cancel"],
            ['POST', "scheduled-calls/{$scheduled->id}/retry"],
        ];
        foreach ($operationalRoutes as [$method, $path]) {
            $this->json($method, '/api/voice/'.$path, [])->assertForbidden()
                ->assertJsonPath('resource', 'voice-calls')->assertJsonPath('action', 'operate');
        }
        foreach ($this->managementRoutes($number, $override) as [$method, $path]) {
            $this->json($method, '/api/voice/'.$path, [])->assertForbidden()
                ->assertJsonPath('resource', 'voice-calls')->assertJsonPath('action', 'manage');
        }
        Http::assertNothingSent();
    }

    private function managementRoutes(SmsNumber $number, VoiceScheduleOverride $override): array
    {
        return [
            ['POST', 'schedule/overrides'],
            ['DELETE', "schedule/overrides/{$override->id}"],
            ['PATCH', "numbers/{$number->id}"],
            ['PATCH', 'settings'],
            ['POST', 'assistant/sync'],
        ];
    }
}
