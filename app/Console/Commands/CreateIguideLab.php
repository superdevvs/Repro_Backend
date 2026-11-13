<?php

namespace App\Console\Commands;

use App\Models\IguideLabProject;
use App\Services\IguideLab\LabStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateIguideLab extends Command
{
    protected $signature = 'iguide-lab:create {name} {--days=30} {--max-runs=60} {--token-file= : New private file for the capability; never print it}';
    protected $description = 'Create an isolated, expiring and generation-limited panorama lab';

    public function handle(): int
    {
        $file = $this->option('token-file');
        if (! is_string($file) || $file === '' || file_exists($file) || ! is_dir(dirname($file))) {
            $this->error('Choose a new token file in an existing private directory.');
            return self::FAILURE;
        }
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
        $limit = filter_var($this->option('max-runs'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        if (! $days || ! $limit) { $this->error('Use 1–30 days and 1–120 generations.'); return self::FAILURE; }
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        app(LabStorage::class)->prepareProject($id);
        $previous = umask(0077);
        try {
            $handle = fopen($file, 'x');
            if (! $handle) throw new \RuntimeException('The private token file could not be created.');
            try { if (fwrite($handle, $token) !== strlen($token)) throw new \RuntimeException('The token file could not be written.'); }
            finally { fclose($handle); }
        } finally { umask($previous); }
        IguideLabProject::create(['id' => $id, 'name' => mb_substr($this->argument('name'), 0, 160), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays($days), 'max_runs' => $limit]);
        $this->line('Project '.$id.' created. Capability saved privately; it was not printed.');

        return self::SUCCESS;
    }
}
