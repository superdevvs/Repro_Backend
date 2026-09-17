<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploadsLogChannelTest extends TestCase
{
    #[Test]
    public function uploads_channel_level_is_pinned_to_info_independent_of_log_level(): void
    {
        $source = (string) file_get_contents(config_path('logging.php'));
        $this->assertNotFalse(preg_match("/'uploads'\\s*=>\\s*\\[(.*?)\\n\\s*\\],/s", $source, $matches));
        $uploads = $matches[1];

        $this->assertMatchesRegularExpression("/'level'\\s*=>\\s*'info'/", $uploads);
        $this->assertStringNotContainsString('LOG_LEVEL', $uploads);
        $this->assertSame('info', config('logging.channels.uploads.level'));
    }

    #[Test]
    public function uploads_info_logs_keep_intake_context_when_the_app_is_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['logging.channels.single.level' => 'error']);

        $logFile = storage_path('logs/uploads-channel-tdd.log');
        config([
            'logging.channels.uploads.driver' => 'single',
            'logging.channels.uploads.path' => $logFile,
            'logging.channels.uploads.level' => 'info',
            'logging.channels.uploads.permission' => 0660,
        ]);
        Log::forgetChannel('uploads');
        if (is_file($logFile)) {
            unlink($logFile);
        }

        $correlationId = 'corr-'.bin2hex(random_bytes(8));
        Log::channel('uploads')->info('Upload request received.', [
            'correlation_id' => $correlationId,
            'shoot_id' => 91,
        ]);

        $this->assertFileExists($logFile);
        $contents = (string) file_get_contents($logFile);
        $this->assertStringContainsString('Upload request received.', $contents);
        $this->assertStringContainsString($correlationId, $contents);
        $this->assertStringContainsString('91', $contents);

        @unlink($logFile);
    }
}
