<?php

namespace App\Console\Commands;

use App\Models\AryeoConnection;
use App\Models\User;
use Illuminate\Console\Command;

class AryeoConnectionCommand extends Command
{
    protected $signature = 'aryeo:connection {name} {--company=} {--clients=} {--token-file=} {--enable-processing} {--allow-shoots=} {--disable-processing} {--enable-discovery} {--disable-discovery} {--revoke}
        {--record-state= : Write the current settings to this new private file (freeze)}
        {--pause : With --record-state, then turn off processing (job claims, so no Aryeo action starts) and email-less discovery}
        {--resume-processing= : Restore exactly the settings recorded in this file}';

    protected $description = 'Provision or rotate a client-scoped Aryeo worker credential without printing secrets';

    /** Connection settings a freeze records and a resume restores. */
    private const RECORDED = ['enabled', 'processing_enabled', 'discover_all_clients', 'delivery_shoot_ids', 'client_ids'];

    /** The settings --pause turns off, and so the only ones a resume turns back on. */
    private const PAUSED = ['processing_enabled', 'discover_all_clients'];

    public function handle(): int
    {
        $connection = AryeoConnection::where('name', $this->argument('name'))->first();
        if ($this->option('resume-processing') !== null) {
            return $this->resume($connection, (string) $this->option('resume-processing'));
        }
        if ($this->option('record-state') !== null || $this->option('pause')) {
            return $this->record($connection, (string) $this->option('record-state'), (bool) $this->option('pause'));
        }
        if ($this->option('revoke') || $this->option('disable-processing') || $this->option('enable-processing') || $this->option('enable-discovery') || $this->option('disable-discovery')) {
            if (! $connection) {
                $this->error('Connection not found.');

                return self::FAILURE;
            }
            if ($this->option('revoke')) {
                $connection->enabled = false;
            }
            if ($this->option('disable-processing')) {
                $connection->processing_enabled = false;
            }
            if ($this->option('enable-discovery') && $this->option('disable-discovery')) {
                $this->error('Choose either --enable-discovery or --disable-discovery.');

                return self::FAILURE;
            }
            if ($this->option('enable-discovery') || $this->option('disable-discovery')) {
                $connection->discover_all_clients = (bool) $this->option('enable-discovery');
            }
            if ($this->option('enable-processing')) {
                $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->option('allow-shoots'))))));
                if (! $ids || \App\Models\Shoot::whereIn('client_id', $connection->client_ids)->whereIn('id', $ids)->count() !== count($ids)) {
                    $this->error('Specify --allow-shoots with selected shoot IDs inside this connection scope.');

                    return self::FAILURE;
                }
                $connection->delivery_shoot_ids = $ids;
                $connection->processing_enabled = true;
            }
            $connection->save();
            $this->info('Connection updated.');

            return self::SUCCESS;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->option('clients'))))));
        $path = $this->option('token-file');
        if (! $ids || ! $this->option('company') || ! $path || file_exists($path) || ! is_dir(dirname($path))) {
            $this->error('Require --company, explicit --clients IDs and a new --token-file in an existing private directory.');

            return self::FAILURE;
        }
        if (User::whereIn('id', $ids)->where('role', 'client')->count() !== count($ids)) {
            $this->error('Every allowed ID must be an existing client.');

            return self::FAILURE;
        }
        $token = 'aryeo_'.bin2hex(random_bytes(32));
        $oldUmask = umask(0077);
        try {
            $file = fopen($path, 'x');
            if (! $file) {
                throw new \RuntimeException('Cannot create credential file.');
            }
            chmod($path, 0600);
            fwrite($file, $token."\n");
            fclose($file);
            AryeoConnection::updateOrCreate(['name' => $this->argument('name')], ['company_key' => $this->option('company'),
                'client_ids' => $ids, 'token_hash' => hash('sha256', $token), 'enabled' => true, 'processing_enabled' => false]);
        } finally {
            umask($oldUmask);
        }
        $this->info('Credential saved to the private file. Processing remains disabled. Rotation invalidates the old credential.');

        return self::SUCCESS;
    }

    /**
     * Freeze: write the settings to a new 0600 file, then optionally pause. No
     * secret is written. The pause stops job claims, so no Aryeo action starts;
     * like --disable-processing it leaves request sync (heartbeat, discovery
     * with a requester email, inventory) on. HTTP writes during the freeze are
     * stopped by the writer freeze (admission closed before Laravel).
     */
    private function record(?AryeoConnection $connection, string $path, bool $pause): int
    {
        if (! $connection) {
            $this->error('Connection not found.');

            return self::FAILURE;
        }
        if ($path === '' || file_exists($path) || ! is_dir(dirname($path))) {
            $this->error('Give --record-state a new file in an existing private directory. --pause needs it.');

            return self::FAILURE;
        }
        $settings = $connection->only(self::RECORDED);
        $state = ['version' => 1, 'name' => $connection->name, 'id' => $connection->id, 'recorded_at' => now()->toIso8601String(),
            'settings' => $settings, 'settings_sha256' => $this->digest($settings)];
        $oldUmask = umask(0077);
        try {
            $file = fopen($path, 'x');
            if (! $file) {
                throw new \RuntimeException('Cannot create the state file.');
            }
            chmod($path, 0600);
            fwrite($file, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            fclose($file);
        } finally {
            umask($oldUmask);
        }
        if ($pause) {
            $connection->forceFill(['processing_enabled' => false, 'discover_all_clients' => false])->save();
        }
        $this->info('Settings recorded'.($pause ? '; job claims and email-less discovery are off.' : '.').' Resume with --resume-processing='.$path);
        if ($pause) {
            // Like --disable-processing: request sync stays on. The writer freeze stops HTTP writes.
            $this->line('Request sync and heartbeats continue; check that no Aryeo job is still leased before the freeze.');
        }

        return self::SUCCESS;
    }

    /**
     * Resume: restore exactly what was recorded, for the same connection, from an
     * unedited file, and only when nothing but the paused settings changed since.
     */
    private function resume(?AryeoConnection $connection, string $path): int
    {
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : null;
        if (! $connection || ! $settings || ($state['version'] ?? null) !== 1
            || ($state['name'] ?? null) !== $connection->name || ($state['id'] ?? null) !== $connection->id
            || array_keys($settings) !== self::RECORDED
            || ! hash_equals((string) ($state['settings_sha256'] ?? ''), $this->digest($settings))) {
            $this->error('The state file does not match this connection or was edited. Nothing was changed.');

            return self::FAILURE;
        }
        $before = $connection->only(self::RECORDED);
        // Turn back on only what the pause turned off. Anything else changed since
        // the recording (a revoked credential, a narrowed scope) was deliberate.
        $changed = array_filter(self::RECORDED, fn (string $key) => $before[$key] !== $settings[$key]
            && ! (in_array($key, self::PAUSED, true) && $before[$key] === false));
        if ($changed !== []) {
            $this->error('The connection changed after the recording ('.implode(', ', $changed).'). Nothing was changed; restore it by hand.');

            return self::FAILURE;
        }
        $connection->forceFill($settings)->save();
        if ($connection->fresh()->only(self::RECORDED) !== $settings) {
            $this->error('The restored settings differ from the recorded ones. Check the connection now.');

            return self::FAILURE;
        }
        foreach (self::RECORDED as $key) {
            if ($before[$key] !== $settings[$key]) {
                $this->line($key.': '.json_encode($before[$key]).' -> '.json_encode($settings[$key]));
            }
        }
        $this->info('Recorded settings restored. Check that the agent is seen again within a minute.');

        return self::SUCCESS;
    }

    private function digest(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
    }
}
