<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\CakemailProvider;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasswordResetEmailLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_button_keeps_its_parameters_without_click_rewriting_on_initial_send_and_retry(): void
    {
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        config([
            'services.cakemail.username' => 'mailer@example.com',
            'services.cakemail.password' => 'synthetic-password',
            'services.cakemail.sender_id' => 'sender-default',
            'services.cakemail.base_url' => 'https://cakemail.example/api',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://cakemail.example/api/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://cakemail.example/api/v2/emails' => Http::response(['data' => ['id' => 'msg-reset-link']]),
        ]);
        // Exercise the real payload builder behind an HTTP fake instead of the
        // safety provider's in-memory Cakemail double.
        $this->app->instance(CakemailProvider::class, new CakemailProvider);
        MessageChannel::create([
            'type' => 'EMAIL', 'provider' => 'CAKEMAIL', 'display_name' => 'Default',
            'from_email' => 'contact@reprophotos.com', 'is_default' => true, 'owner_scope' => 'GLOBAL',
        ]);
        $user = User::factory()->create(['role' => 'client', 'email' => 'reader+reset@example.com']);
        $token = str_repeat('aB12', 16);
        $resetLink = 'https://reprodashboard.com/reset-password?token='.$token.'&email='.urlencode($user->email);

        $this->assertTrue(app(MailService::class)->sendPasswordResetEmail($user, $resetLink));
        $this->assertResetRequests(1, $resetLink, $token, $user->email);

        // Stored/scheduled retries reconstruct the provider payload from the DB.
        $message = Message::query()->where('send_source', 'PASSWORD_RESET')->sole();
        $this->assertSame('SENT', $message->status);
        app(MessagingService::class)->dispatchStoredEmailMessage($message->fresh());
        $this->assertResetRequests(2, $resetLink, $token, $user->email);
    }

    private function assertResetRequests(int $expectedCount, string $resetLink, string $token, string $email): void
    {
        $requests = Http::recorded(fn (Request $request): bool => $request->url() === 'https://cakemail.example/api/v2/emails');
        $this->assertCount($expectedCount, $requests);

        foreach ($requests as [$request]) {
            $this->assertSame(['opens' => true, 'clicks_html' => false, 'clicks_text' => false], $request['tracking']);
            $html = $request['content']['html'];
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML($html, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $buttons = [];
            foreach ($document->getElementsByTagName('a') as $anchor) {
                if (trim($anchor->textContent) === 'Reset Password') {
                    $buttons[] = $anchor->getAttribute('href');
                }
            }
            $this->assertSame([$resetLink], $buttons);
            parse_str(parse_url($buttons[0], PHP_URL_QUERY), $parameters);
            $this->assertSame(['token' => $token, 'email' => $email], $parameters);
            $this->assertStringContainsString($resetLink, $document->textContent);
            $this->assertStringContainsString($resetLink, $request['content']['text']);
            $this->assertStringNotContainsString('&amp;amp;email=', $html);
        }
    }
}
