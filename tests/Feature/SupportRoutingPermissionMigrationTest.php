<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\RolePermissionService;
use App\Services\SupportTicketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupportRoutingPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function applyUpgrade(array $roles): array
    {
        Setting::updateOrCreate(['key' => 'permissions.role_map.v1'], ['value' => json_encode(['roles' => $roles])]);
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropUnique(['source_message_id']);
            $table->dropColumn(['source_message_id', 'attachments_json']);
        });
        (require database_path('migrations/2026_10_01_160000_add_support_message_sources_and_attachments.php'))->up();

        return json_decode(Setting::where('key', 'permissions.role_map.v1')->value('value'), true)['roles'];
    }

    public function test_only_untouched_editing_manager_default_is_upgraded_and_user_denial_survives(): void
    {
        $roles = app(RolePermissionService::class)->defaultPermissionsByRole();
        $roles['editing_manager'] = array_values(array_diff($roles['editing_manager'], ['support-manage']));
        $updated = $this->applyUpgrade($roles);
        $this->assertContains('support-manage', $updated['editing_manager']);
        $this->assertSame($roles['client'], $updated['client']);
        $manager = User::factory()->create(['role' => 'editing_manager', 'permission_overrides' => ['allow' => [], 'deny' => ['support-manage']]]);
        $this->assertFalse(app(SupportTicketService::class)->canManage($manager));
    }

    public function test_customized_and_empty_roles_are_not_silently_granted_private_ticket_access(): void
    {
        $roles = ['editing_manager' => ['support-view', 'messaging-email-view'], 'admin' => [], 'client' => ['support-view']];
        $this->assertSame($roles, $this->applyUpgrade($roles));
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $this->assertFalse(app(SupportTicketService::class)->canManage($manager));
    }
}
