<?php

namespace App\Console\Commands;

use App\Models\AryeoConnection;
use App\Models\User;
use Illuminate\Console\Command;

class AryeoConnectionCommand extends Command
{
    protected $signature = 'aryeo:connection {name} {--company=} {--clients=} {--token-file=} {--enable-processing} {--allow-shoots=} {--disable-processing} {--revoke}';

    protected $description = 'Provision or rotate a client-scoped Aryeo worker credential without printing secrets';

    public function handle(): int
    {
        $connection = AryeoConnection::where('name', $this->argument('name'))->first();
        if ($this->option('revoke') || $this->option('disable-processing') || $this->option('enable-processing')) {
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
}
