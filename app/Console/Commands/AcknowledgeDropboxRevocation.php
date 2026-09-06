<?php

namespace App\Console\Commands;

use App\Services\DropboxTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AcknowledgeDropboxRevocation extends Command
{
    protected $signature = 'dropbox:acknowledge-revocation
        {connection_version : Current connection version from the administrator Dropbox status}
        {--apply : Clear retained retry credentials after verified provider revocation}
        {--confirm-provider-revoked : Confirm an operator verified app revocation in Dropbox}';

    protected $description = 'Acknowledge externally verified Dropbox revocation; read-only unless both confirmation flags are supplied.';

    public function handle(DropboxTokenService $tokens): int
    {
        $apply = (bool) $this->option('apply');
        $confirmed = (bool) $this->option('confirm-provider-revoked');
        if ($apply && !$confirmed) {
            $this->error('Verify app revocation in Dropbox, then supply --confirm-provider-revoked with --apply.');
            return self::FAILURE;
        }
        try {
            $result = $tokens->acknowledgeProviderRevocation((string) $this->argument('connection_version'), $apply, $confirmed);
            $this->info($apply ? 'Provider revocation acknowledged. Studio Dropbox remains disconnected.' : 'Dry run: pending revocation can be acknowledged; no credentials changed.');
            $this->line('Connection version: '.$result['connection_version']);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::warning('Dropbox revocation acknowledgement was not completed.', ['exception' => $exception::class]);
            $this->error('Acknowledgement failed. Verify the current connection version and that Dropbox is disconnected with revocation pending.');
            return self::FAILURE;
        }
    }
}
