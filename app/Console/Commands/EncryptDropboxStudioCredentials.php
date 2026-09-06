<?php

namespace App\Console\Commands;

use App\Services\DropboxTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EncryptDropboxStudioCredentials extends Command
{
    protected $signature = 'dropbox:encrypt-studio-credentials {--apply : Encrypt legacy studio credentials in the database}';

    protected $description = 'Inspect legacy studio Dropbox credential encryption; read-only unless --apply is supplied.';

    public function handle(DropboxTokenService $tokens): int
    {
        try {
            $apply = (bool) $this->option('apply');
            $result = $tokens->encryptLegacyCredentials($apply);
            $this->info($apply ? 'Studio Dropbox credential encryption complete.' : 'Dry run: no credentials changed.');
            $this->line('Studio records scanned: '.$result['records_scanned']);
            $this->line('Records needing encryption: '.$result['records_needing_encryption']);
            $this->line('Records updated: '.$result['records_updated']);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error('Studio Dropbox credential encryption failed.', ['exception' => $exception::class]);
            $this->error('Credential encryption could not be completed. Check database, encryption key and cache availability.');
            return self::FAILURE;
        }
    }
}
