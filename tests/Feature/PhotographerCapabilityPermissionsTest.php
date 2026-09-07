<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhotographerCapabilityPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public static function selfCapabilityPayloads(): array
    {
        return [
            'specialties' => [['specialties' => ['category:new']]],
            'property experience' => [['property_types' => ['Office']]],
            'metadata' => [['metadata' => ['specialties' => ['category:new']]]],
            'encoded metadata' => [['metadata' => '{"property_types":["Office"]}']],
            'preferences' => [['preferences' => ['specialties' => ['category:new']]]],
            'nested preferences' => [['preferences' => ['nested' => ['property_types' => ['Office']]]]],
            'encoded nested metadata' => [['preferences' => ['metadata' => '{"specialties":["category:new"]}']]],
            'camel case alias' => [['preferences' => ['propertyTypes' => ['Office']]]],
            'clearing assigned values' => [['specialties' => [], 'property_types' => []]],
        ];
    }

    #[DataProvider('selfCapabilityPayloads')]
    public function test_photographer_cannot_write_managed_capabilities_from_self_profile(array $payload): void
    {
        $photographer = $this->photographer();
        $before = $photographer->metadata;
        $this->withToken($photographer->createToken('self')->plainTextToken)
            ->putJson('/api/profile', array_merge(['name' => 'Must not persist'], $payload))
            ->assertForbidden();
        $this->assertSame($before, $photographer->fresh()->metadata);
        $this->assertNotSame('Must not persist', $photographer->fresh()->name);
    }

    public function test_photographer_can_still_save_work_and_notification_preferences_without_changing_capabilities(): void
    {
        $photographer = $this->photographer();
        $this->withToken($photographer->createToken('self')->plainTextToken)
            ->putJson('/api/profile', [
                'name' => 'Updated Photographer', 'travel_range' => 40, 'default_bracket_mode' => 3,
                'preferences' => ['weeklyInvoice' => false, 'notificationEmail' => false],
            ])->assertOk();
        $metadata = $photographer->fresh()->metadata;
        $this->assertSame(['category:old'], $metadata['specialties']);
        $this->assertSame(['Single Family'], $metadata['property_types']);
        $this->assertSame(40, $metadata['travel_range']);
        $this->assertFalse($metadata['preferences']['weeklyInvoice']);
    }

    public static function privilegedRoles(): array
    {
        return [['admin'], ['superadmin']];
    }

    #[DataProvider('privilegedRoles')]
    public function test_admin_can_assign_both_capabilities_when_creating_a_photographer(string $role): void
    {
        $admin = User::factory()->create(['role' => $role]);
        $this->mock(\App\Services\Users\AccountCreatedNotificationService::class)
            ->shouldReceive('dispatch')->once()->andReturn([
                'email' => ['account_created' => [], 'verification' => [], 'equipment' => []], 'sms' => [], 'links' => [],
            ]);
        $this->withToken($admin->createToken('admin-create')->plainTextToken)
            ->postJson('/api/admin/users', [
                'name' => 'New Photographer', 'email' => 'capabilities@example.com', 'role' => 'photographer',
                'specialties' => json_encode(['category:new']),
                'metadata' => json_encode(['property_types' => ['Office']]),
            ])->assertCreated();
        $created = User::where('email', 'capabilities@example.com')->firstOrFail();
        $this->assertSame(['category:new'], $created->metadata['specialties']);
        $this->assertSame(['Office'], $created->metadata['property_types']);
    }

    #[DataProvider('privilegedRoles')]
    public function test_admin_can_manage_and_clear_both_capabilities_in_account_management(string $role): void
    {
        $admin = User::factory()->create(['role' => $role]);
        $photographer = $this->photographer();
        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->putJson("/api/admin/users/{$photographer->id}", [
                'metadata' => json_encode(['specialties' => ['category:new'], 'property_types' => ['Office']]),
            ])->assertOk();
        $this->assertSame(['category:new'], $photographer->fresh()->metadata['specialties']);
        $this->assertSame(['Office'], $photographer->fresh()->metadata['property_types']);
        $this->putJson("/api/admin/users/{$photographer->id}", [
            'metadata' => ['specialties' => [], 'property_types' => []],
        ])->assertOk();
        $this->assertSame([], $photographer->fresh()->metadata['specialties']);
        $this->assertSame([], $photographer->fresh()->metadata['property_types']);
    }

    public function test_secondary_admin_role_does_not_allow_photographer_capability_changes(): void
    {
        $photographer = $this->photographer(['secondary_roles' => ['admin']]);
        $this->withToken($photographer->createToken('secondary-role')->plainTextToken)
            ->putJson("/api/admin/users/{$photographer->id}", [
                'metadata' => ['specialties' => ['category:new']],
            ])->assertForbidden();
        $this->assertSame(['category:old'], $photographer->fresh()->metadata['specialties']);
    }

    public function test_impersonation_does_not_restore_admin_capability_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = $this->photographer(['secondary_roles' => ['admin']]);
        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->withHeader('X-Impersonate-User-Id', (string) $photographer->id)
            ->putJson("/api/admin/users/{$photographer->id}", [
                'metadata' => ['property_types' => ['Office']],
            ])->assertForbidden();
        $this->assertSame(['Single Family'], $photographer->fresh()->metadata['property_types']);
    }

    private function photographer(array $overrides = []): User
    {
        return User::factory()->photographer()->create(array_merge([
            'metadata' => ['specialties' => ['category:old'], 'property_types' => ['Single Family'], 'preferences' => ['weeklyInvoice' => true]],
        ], $overrides));
    }
}
