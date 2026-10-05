<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\FakeEmailProvider;
use App\Services\RolePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailNotificationPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        FakeEmailProvider::reset();
    }

    public function test_role_toggle_blocks_booking_but_not_reminders_or_manual_mail(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $permissions = app(RolePermissionService::class);
        $roles = $permissions->defaultPermissionsByRole();
        $roles['admin'] = array_values(array_diff($roles['admin'], ['email-notifications-bookingUpdates']));
        $permissions->updatePermissions($roles);
        $mail = app(MessagingService::class);
        $this->assertSame('BLOCKED', $mail->sendEmail(['to' => $user->email, 'send_source' => 'SHOOT_REQUESTED', 'body_text' => 'Booking'])->status);
        $this->assertSame('SENT', $mail->sendEmail(['to' => $user->email, 'send_source' => 'SHOOT_REMINDER', 'body_text' => 'Reminder'])->status);
        $this->assertSame('SENT', $mail->sendEmail(['to' => $user->email, 'send_source' => 'MANUAL', 'body_text' => 'Personal'])->status);
        $this->assertCount(2, FakeEmailProvider::sent());
    }

    public function test_queued_mail_rechecks_individual_denial_and_filters_cc_bcc(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $mail = app(MessagingService::class);
        $queued = $mail->scheduleEmail(['to' => $user->email, 'send_source' => 'SHOOT_UPDATED', 'body_text' => 'Update'], now()->addMinute());
        $user->update(['permission_overrides' => ['allow' => [], 'deny' => ['email-notifications-bookingUpdates']]]);
        $this->assertSame('BLOCKED', $mail->dispatchStoredEmailMessage($queued)->status);
        $message = $mail->sendEmail(['to' => 'external@example.test', 'cc' => [$user->email], 'bcc' => [$user->email], 'send_source' => 'SHOOT_UPDATED', 'body_text' => 'Update']);
        $this->assertSame([], $message->cc_addresses_json);
        $this->assertSame([], $message->bcc_addresses_json);
        $this->assertCount(1, FakeEmailProvider::sent());
    }

    public function test_upgrade_preserves_email_delivery_and_admin_payment_permission_then_allows_revocation(): void
    {
        $permissions = app(RolePermissionService::class);
        $permissions->updatePermissions(['admin' => ['dashboard-view']]);
        $migration = require database_path('migrations/2026_10_05_180000_add_email_and_admin_payment_permissions.php');
        $migration->up();
        $user = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($permissions->userCan($user, 'payments', 'mark-paid'));
        $this->assertTrue($permissions->userCan($user, 'email-notifications', 'bookingUpdates'));
        $permissions->updatePermissions(['admin' => ['dashboard-view']]);
        $this->assertFalse($permissions->userCan($user, 'payments', 'mark-paid'));
        $this->assertFalse($permissions->userCan($user, 'email-notifications', 'bookingUpdates'));
    }
}
