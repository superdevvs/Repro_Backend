<?php

namespace Tests\Unit\Users;

use App\Models\ClientEmailVerificationToken;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\Users\AccountCreatedNotificationService;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\EmailHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountCreatedNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_dispatches_truthful_role_policy_for_all_supported_roles(string $role, bool $verify): void
    {
        [$service, $mail, $automation, $messaging] = $this->service();
        $user = $this->user($role, '(410) 555-0123');

        $automation->expects($this->once())->method('handleEvent')->willReturn(['email_sent_to' => []]);
        $mail->expects($this->once())->method('sendAccountCreatedEmail')->willReturn(true);
        $mail->expects($verify ? $this->once() : $this->never())->method('sendClientEmailVerificationEmail')->willReturn(true);
        $mail->expects($this->never())->method('sendPhotographerEquipmentVerificationEmail');
        $mail->expects($this->never())->method('equipmentVerificationLink');
        $messaging->expects($this->once())->method('sendSms')->with($this->callback(fn (array $payload) => $payload['to'] === '+14105550123'))->willReturn(new Message);

        $result = $service->dispatch($user);

        $this->assertTrue($result['email']['account_created']['sent']);
        $this->assertSame($verify, $result['email']['verification']['attempted']);
        $this->assertSame($verify, $result['email']['verification']['sent']);
        $this->assertTrue($result['sms']['sent']);
        $this->assertFalse($result['email']['equipment']['attempted']);
        $this->assertFalse($result['email']['equipment']['sent']);
        $this->assertNull($result['links']['equipment']);
    }

    public function test_equipment_email_and_welcome_link_require_pending_assigned_equipment(): void
    {
        [$service, $mail, $automation] = $this->service();
        $user = $this->user('photographer');
        $equipmentLink = 'https://app.test/equipment';

        $mail->expects($this->once())->method('equipmentVerificationLink')->with($user)->willReturn($equipmentLink);
        $automation->expects($this->once())->method('handleEvent')
            ->with('ACCOUNT_CREATED', $this->callback(fn (array $context) => $context['equipment_verification_link'] === $equipmentLink && $context['pending_equipment_count'] === 2))
            ->willReturn(['email_sent_to' => []]);
        $mail->expects($this->once())->method('sendAccountCreatedEmail')
            ->with($user, 'https://app.test/reset/token', 'https://app.test/verify/token', $equipmentLink, 2, false)
            ->willReturn(true);
        $mail->expects($this->once())->method('sendPhotographerEquipmentVerificationEmail')->with($user, 2)->willReturn(true);

        $result = $service->dispatch($user, ['pending_equipment_count' => 2]);

        $this->assertTrue($result['email']['equipment']['sent']);
        $this->assertSame($equipmentLink, $result['links']['equipment']);
    }

    public function test_explicit_equipment_email_option_does_not_send_without_equipment(): void
    {
        [$service, $mail, $automation] = $this->service();
        $user = $this->user('photographer');
        $mail->expects($this->never())->method('equipmentVerificationLink');
        $mail->expects($this->never())->method('sendPhotographerEquipmentVerificationEmail');
        $automation->expects($this->once())->method('handleEvent')
            ->with('ACCOUNT_CREATED', $this->callback(fn (array $context) => $context['equipment_verification_link'] === null))
            ->willReturn(['email_sent_to' => []]);
        $mail->expects($this->once())->method('sendAccountCreatedEmail')
            ->with($user, 'https://app.test/reset/token', 'https://app.test/verify/token', null, 0, false)
            ->willReturn(true);

        $result = $service->dispatch($user, ['pending_equipment_count' => 0, 'send_equipment_email' => true]);

        $this->assertSame(['attempted' => false, 'sent' => false, 'error' => null], $result['email']['equipment']);
    }

    public function test_recipient_acceptance_suppresses_fallback_but_unrelated_acceptance_does_not(): void
    {
        [$service, $mail, $automation] = $this->service();
        $user = $this->user('admin');
        $automation->method('handleEvent')->willReturn(['email_sent_to' => ['qa@example.test']]);
        $mail->expects($this->never())->method('sendAccountCreatedEmail');
        $this->assertTrue($service->dispatch($user)['email']['account_created']['sent']);

        [$service2, $mail2, $automation2] = $this->service();
        $automation2->method('handleEvent')->willReturn(['email_sent_to' => ['someone-else@example.test']]);
        $mail2->expects($this->once())->method('sendAccountCreatedEmail')->willReturn(true);
        $this->assertTrue($service2->dispatch($user)['email']['account_created']['sent']);
    }

    public function test_channels_are_independent_and_report_provider_failures(): void
    {
        [$service, $mail, $automation, $messaging] = $this->service();
        $user = $this->user('salesRep', 'invalid');
        $automation->method('handleEvent')->willThrowException(new \RuntimeException('email unavailable'));
        $mail->expects($this->once())->method('sendClientEmailVerificationEmail')->willReturn(true);
        $messaging->expects($this->never())->method('sendSms');

        $result = $service->dispatch($user);
        $this->assertFalse($result['email']['account_created']['sent']);
        $this->assertSame('email unavailable', $result['email']['account_created']['error']);
        $this->assertTrue($result['email']['verification']['sent']);
        $this->assertFalse($result['sms']['sent']);
        $this->assertNotNull($result['sms']['error']);
        $this->assertSame('sales_rep', $service->normalizeRole('salesRep'));
        $this->assertSame('sales_rep', $service->normalizeRole('sales_rep'));
    }

    public function test_no_phone_skips_sms_truthfully(): void
    {
        [$service, $mail, $automation, $messaging] = $this->service();
        $automation->method('handleEvent')->willReturn(['email_sent_to' => ['qa@example.test']]);
        $messaging->expects($this->never())->method('sendSms');
        $result = $service->dispatch($this->user('admin'));
        $this->assertSame(['attempted' => false, 'sent' => false, 'error' => null], $result['sms']);
    }

    public function test_disabled_configured_welcome_does_not_fall_back_to_hardcoded_email(): void
    {
        [$service, $mail, $automation, $messaging] = $this->service(false);
        $automation->method('handleEvent')->willReturn(['email_sent_to' => []]);
        $mail->expects($this->never())->method('sendAccountCreatedEmail');
        $messaging->expects($this->never())->method('sendSms');
        $result = $service->dispatch($this->user('admin', '+14105550123'));
        $this->assertFalse($result['email']['account_created']['sent']);
        $this->assertFalse($result['sms']['attempted']);
        $this->assertFalse($result['sms']['sent']);
    }

    public function test_saved_welcome_sms_is_reported_without_a_second_hardcoded_send(): void
    {
        [$service, $mail, $automation, $messaging] = $this->service(false);
        $automation->method('handleEvent')->willReturn(['email_sent_to' => ['qa@example.test'], 'sms_sent_to' => ['+14105550123']]);
        $mail->expects($this->never())->method('sendAccountCreatedEmail');
        $messaging->expects($this->never())->method('sendSms');
        $result = $service->dispatch($this->user('admin', '(410) 555-0123'));
        $this->assertTrue($result['sms']['sent']);
    }

    public function test_fallback_sms_uses_compact_copy_without_email_address_or_role(): void
    {
        config(['app.frontend_url' => 'https://reprodashboard.com']);
        MessageTemplate::where('slug', 'automation-account-created-sms')->delete();
        [$service, , , $messaging] = $this->service();
        $messaging->expects($this->once())->method('sendSms')
            ->with($this->callback(fn (array $payload) => $payload['body_text'] === 'R/E Pro Photos: Account ready. Check your email for setup. Sign in: https://reprodashboard.com'
                && $payload['template_id'] === null))
            ->willReturn(new Message);

        $this->assertTrue($service->sendSms($this->user('photographer', '(410) 555-0123'))['sent']);
        $this->assertDatabaseMissing('message_templates', ['slug' => 'automation-account-created-sms']);
    }

    public function test_fallback_sms_uses_saved_editable_template(): void
    {
        $template = MessageTemplate::updateOrCreate(['slug' => 'automation-account-created-sms'], [
            'name' => 'Account ready SMS', 'channel' => 'SMS', 'is_active' => true,
            'body_text' => 'Welcome {{recipient_first_name}}. Your account is ready.',
        ]);
        [$service, , , $messaging] = $this->service();
        $messaging->expects($this->once())->method('sendSms')
            ->with($this->callback(fn (array $payload) => $payload['body_text'] === 'Welcome QA. Your account is ready.'
                && $payload['template_id'] === $template->id))
            ->willReturn(new Message);

        $this->assertTrue($service->sendSms($this->user('admin', '(410) 555-0123'))['sent']);
    }

    public function test_disabled_saved_sms_template_suppresses_fallback(): void
    {
        MessageTemplate::updateOrCreate(['slug' => 'automation-account-created-sms'], [
            'name' => 'Account ready SMS', 'channel' => 'SMS', 'is_active' => false,
            'body_text' => 'Disabled welcome',
        ]);
        [$service, , , $messaging] = $this->service();
        $messaging->expects($this->never())->method('sendSms');

        $this->assertSame(['attempted' => false, 'sent' => false, 'error' => null],
            $service->sendSms($this->user('admin', '(410) 555-0123')));
    }

    public static function roles(): array
    {
        return [
            ['superadmin', false], ['admin', false], ['editing_manager', true],
            ['client', true], ['photographer', true], ['editor', true], ['salesRep', true],
        ];
    }

    private function service(bool $legacyFallback = true): array
    {
        $messaging = $this->createMock(MessagingService::class);
        $mail = $this->createMock(MailService::class);
        $automation = $this->createMock(AutomationService::class);
        $links = $this->createMock(ClientEmailVerificationLinkService::class);
        $health = $this->createMock(EmailHealthService::class);
        $mail->method('generateStoredPasswordResetLink')->willReturn('https://app.test/reset/token');
        $automation->method('buildUserContext')->willReturn([]);
        $automation->method('shouldUseFallback')->willReturn($legacyFallback);
        $token = new ClientEmailVerificationToken;
        $token->id = 44;
        $links->method('issueVerificationToken')->willReturn($token);
        $links->method('buildUrlForIssuedToken')->willReturn('https://app.test/verify/token');

        return [new AccountCreatedNotificationService($messaging, $mail, $automation, $links, $health), $mail, $automation, $messaging];
    }

    private function user(string $role, ?string $phone = null): User
    {
        $user = new User(['name' => 'QA User', 'email' => 'qa@example.test', 'role' => $role, 'phonenumber' => $phone]);
        $user->id = 101;

        return $user;
    }
}
