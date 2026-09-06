<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SecurityCredentialInventory extends Command
{
    protected $signature = 'security:credential-inventory';

    protected $description = 'Read credential presence and consumer counts without exposing or changing secrets';

    public function handle(): int
    {
        $paths = [
            'APP_KEY' => 'app.key',
            'AWS_ACCESS_KEY_ID' => 'filesystems.disks.s3.key',
            'AWS_SECRET_ACCESS_KEY' => 'filesystems.disks.s3.secret',
            'R2_ACCESS_KEY_ID' => 'filesystems.disks.media.key',
            'R2_SECRET_ACCESS_KEY' => 'filesystems.disks.media.secret',
            'REDIS_PASSWORD' => 'database.redis.default.password',
            'MAIL_PASSWORD' => 'mail.mailers.smtp.password',
            'CAKEMAIL_USERNAME' => 'services.cakemail.username',
            'CAKEMAIL_PASSWORD' => 'services.cakemail.password',
            'CAKEMAIL_WEBHOOK_SECRET' => 'services.cakemail.webhook_secret',
            'DROPBOX_CLIENT_ID' => 'services.dropbox.client_id',
            'DROPBOX_CLIENT_SECRET' => 'services.dropbox.client_secret',
            'DROPBOX_ACCESS_TOKEN' => 'services.dropbox.access_token',
            'DROPBOX_REFRESH_TOKEN' => 'services.dropbox.refresh_token',
            'GOOGLE_CLIENT_SECRET' => 'services.google.client_secret',
        ];
        $credentials = [];
        foreach ($paths as $name => $path) {
            $credentials[$name] = [
                'configured' => filled(config($path)),
                'consumer' => $path,
                'provider_revocation_verified' => false,
            ];
        }
        $counts = [];
        foreach (['oauth_tokens', 'google_calendar_connections', 'user_tax_documents'] as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }
        $counts['users_with_mfa_secret'] = Schema::hasColumn('users', 'two_factor_secret')
            ? DB::table('users')->whereNotNull('two_factor_secret')->count() : null;
        $this->line(json_encode([
            'read_only' => true,
            'observed_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'database_driver' => config('database.default'),
            'cache_store' => config('cache.default'),
            'media' => [
                'dual_write' => (bool) config('media.dual_write'),
                'read_from_r2' => (bool) config('media.read_from_r2'),
                'r2_only' => (bool) config('media.r2_only'),
            ],
            'credentials' => $credentials,
            'encrypted_consumer_row_counts' => $counts,
            'app_key_cutover' => 'deferred_pending_verified_recovery',
            'archive_values_compared' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
