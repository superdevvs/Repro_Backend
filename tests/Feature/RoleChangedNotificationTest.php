<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleChangedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_supported_account_roles_reach_the_email_dispatcher(): void
    {
        $deliveries = [];
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->times(6)
            ->andReturnUsing(function (array $payload) use (&$deliveries): Message {
                $deliveries[] = $payload;

                return new Message(['status' => 'SENT']);
            });

        foreach (['salesRep' => 'rep', 'sales_rep' => 'rep', 'superadmin' => 'admin', 'editor' => 'editor', 'editing_manager' => 'admin', 'photographer' => 'photographer'] as $role => $recipientType) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertTrue(app(MailService::class)->sendRoleChangedEmail($user, 'client', $role), $role);
            $this->assertSame($recipientType, end($deliveries)['contact_type']);
            $this->assertDatabaseHas('system_email_dispatches', ['related_account_id' => $user->id, 'email_alias' => 'ROLE_CHANGED', 'status' => 'sent']);
        }
    }

    public function test_role_changes_dedupe_retries_but_not_later_or_secondary_role_changes(): void
    {
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->times(3)->andReturnUsing(fn () => new Message(['status' => 'SENT']));
        $user = User::factory()->create(['role' => 'photographer', 'updated_at' => '2026-09-27 10:00:00']);
        $mail = app(MailService::class);

        $this->assertTrue($mail->sendRoleChangedEmail($user, 'client', 'photographer'));
        $this->assertTrue($mail->sendRoleChangedEmail($user, 'client', 'photographer'));
        $this->assertSame(1, SystemEmailDispatch::where('email_alias', 'ROLE_CHANGED')->count());
        $this->assertTrue($mail->sendRoleChangedEmail($user, 'client', 'photographer', [], ['editor']));
        $user->updated_at = '2026-09-28 10:00:00';
        $this->assertTrue($mail->sendRoleChangedEmail($user, 'client', 'photographer'));
        $this->assertSame(3, SystemEmailDispatch::where('email_alias', 'ROLE_CHANGED')->count());
    }
}
