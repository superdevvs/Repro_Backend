<?php

namespace Tests\Feature;

use App\Logging\PrivacyLogManager;
use App\Logging\PrivacyLogProcessor;
use Illuminate\Http\Request;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\IsolatedSecurityTestCase;

class RestrictedLogPrivacyTest extends IsolatedSecurityTestCase
{
    public function test_reviewed_diagnostic_location_and_throttle_scope_remain_operationally_useful(): void
    {
        $processor = new PrivacyLogProcessor();
        $record = $processor(new LogRecord(new \DateTimeImmutable(), 'production', Level::Error, 'API request failed.', [
            'exception' => \RuntimeException::class, 'file' => 'app/Services/ApiErrorResponder.php', 'line' => 42,
            'message' => 'secret-canary',
        ]));
        $this->assertSame('app/Services/ApiErrorResponder.php', $record->context['file']);
        $this->assertSame(42, $record->context['line']);
        $throttle = $processor(new LogRecord(new \DateTimeImmutable(), 'production', Level::Notice, 'Authentication rate limit exceeded.', [
            'scope' => 'login-account', 'email' => 'secret-canary',
        ]));
        $this->assertSame('Authentication rate limit exceeded.', $throttle->message);
        $this->assertSame('login-account', $throttle->context['scope']);
        $this->assertStringNotContainsString('secret-canary', json_encode([$record, $throttle]));
    }

    public function test_processor_discards_exception_text_context_extra_and_stack_arguments(): void
    {
        $request = Request::create('/api/test');
        $request->headers->set('X-Trace-Id', 'caller-chosen');
        $this->app->instance('request', $request);
        $record = (new PrivacyLogProcessor())(new LogRecord(new \DateTimeImmutable(), 'production', Level::Error,
            'provider error secret-canary', [
                'exception' => new \RuntimeException('SQL password=secret-canary'),
                'headers' => ['Authorization' => 'Bearer secret-canary'], 'body' => 'secret-canary', 'status_code' => 503,
                'custom' => ['anything' => 'secret-canary'],
            ], ['trace' => ['args' => ['secret-canary']]]));
        $this->assertSame('Application error event.', $record->message);
        $this->assertSame(503, $record->context['status_code']);
        $this->assertSame(\RuntimeException::class, $record->context['exception']);
        $this->assertArrayHasKey('file', $record->context);
        $this->assertArrayHasKey('line', $record->context);
        $this->assertNotSame('caller-chosen', $record->context['request_id']);
        $this->assertStringNotContainsString('secret-canary', json_encode($record));
        $this->assertSame([], $record->extra);
    }

    public function test_production_stack_and_on_demand_channels_cannot_bypass_the_processor(): void
    {
        $this->app['env'] = 'production';
        config(['logging.channels.privacy-test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'logging.channels.privacy-stack' => ['driver' => 'stack', 'channels' => ['privacy-test']]]);
        $manager = new PrivacyLogManager($this->app);
        $manager->channel('privacy-stack')->warning('secret-canary', ['error' => 'secret-canary']);
        $handler = $manager->channel('privacy-test')->getLogger()->getHandlers()[0];
        $this->assertCount(1, $handler->getRecords());
        $this->assertStringNotContainsString('secret-canary', json_encode($handler->getRecords()));

        $onDemand = $manager->build(['driver' => 'monolog', 'handler' => TestHandler::class]);
        $onDemand->error('secret-canary', ['response' => ['token' => 'secret-canary']]);
        $this->assertStringNotContainsString('secret-canary', json_encode($onDemand->getLogger()->getHandlers()[0]->getRecords()));
    }

    public function test_emergency_fallback_also_redacts_when_a_configured_logger_cannot_be_created(): void
    {
        $this->app['env'] = 'production';
        $path = tempnam(sys_get_temp_dir(), 'privacy-log-');
        try {
            config(['logging.channels.emergency.path' => $path,
                'logging.channels.broken' => ['driver' => 'secret-canary-invalid-driver']]);
            $manager = new PrivacyLogManager($this->app);
            $manager->channel('broken')->error('secret-canary', ['body' => 'secret-canary']);
            $contents = file_get_contents($path);
            $this->assertStringContainsString('Application emergency event.', $contents);
            $this->assertStringContainsString('Application error event.', $contents);
            $this->assertStringNotContainsString('secret-canary', $contents);
        } finally {
            if (isset($manager)) $manager->forgetChannel('broken');
            @unlink($path);
        }
    }
}
