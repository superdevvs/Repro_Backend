<?php

namespace Tests\Unit\Users;

use App\Models\ClientEmailVerificationToken;
use App\Models\Message;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\Users\AccountCreatedNotificationService;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\EmailHealthService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountCreatedNotificationServiceTest extends TestCase
{
    #[DataProvider('roles')]
    public function test_dispatches_truthful_role_policy_for_all_supported_roles(string $role, bool $verify): void
    {
        [$service, $mail, $automation, $messaging] = $this->service();
        $user = $this->user($role, '(410) 555-0123');

        $automation->expects($this->once())->method('handleEvent')->willReturn(['email_sent_to' => []]);
        $mail->expects($this->once())->method('sendAccountCreatedEmail')->willReturn(true);
        $mail->expects($verify ? $this->once() : $this->never())->method('sendClientEmailVerificationEmail')->willReturn(true);
        $mail->expects($role === 'photographer' ? $this->once() : $this->never())->method('sendPhotographerEquipmentVerificationEmail')->with($user, 0)->willReturn(true);
        $messaging->expects($this->once())->method('sendSms')->with($this->callback(fn (array $payload) => $payload['to'] === '+14105550123'))->willReturn(new Message());

        $result = $service->dispatch($user);

        $this->assertTrue($result['email']['account_created']['sent']);
        $this->assertSame($verify, $result['email']['verification']['attempted']);
        $this->assertSame($verify, $result['email']['verification']['sent']);
        $this->assertTrue($result['sms']['sent']);
        $this->assertSame($role === 'photographer', $result['email']['equipment']['attempted']);
        $this->assertSame($role === 'photographer', $result['email']['equipment']['sent']);
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

    public static function roles(): array
    {
        return [
            ['superadmin', false], ['admin', false], ['editing_manager', true],
            ['client', true], ['photographer', true], ['editor', true], ['salesRep', true],
        ];
    }

    private function service(): array
    {
        $messaging = $this->createMock(MessagingService::class);
        $mail = $this->createMock(MailService::class);
        $automation = $this->createMock(AutomationService::class);
        $links = $this->createMock(ClientEmailVerificationLinkService::class);
        $health = $this->createMock(EmailHealthService::class);
        $mail->method('generateStoredPasswordResetLink')->willReturn('https://app.test/reset/token');
        $automation->method('buildUserContext')->willReturn([]);
        $token = new ClientEmailVerificationToken();
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
