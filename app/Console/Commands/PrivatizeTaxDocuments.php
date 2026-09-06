<?php

namespace App\Console\Commands;

use App\Services\TaxDocumentLegacyMigration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PrivatizeTaxDocuments extends Command
{
    protected $signature = 'tax-documents:privatize {--apply : Copy verified legacy documents to private storage and remove public copies}';
    protected $description = 'Inspect legacy tax-document migration; read-only by default and aggregate output only.';

    public function handle(TaxDocumentLegacyMigration $migration): int
    {
        try {
            $apply = (bool) $this->option('apply');
            $report = $migration->run($apply);
            $this->info($apply ? 'Private tax document migration finished.' : 'Dry run: no files or records changed.');
            foreach ($report as $key => $count) { $this->line($key.': '.$count); }
            if ($report['missing'] + $report['invalid_paths'] + $report['conflicts'] + $report['orphan_files'] + $report['failures'] > 0) {
                $this->warn('Some documents need operator review. Keep the public tax-document URL prefix blocked.');
                return self::FAILURE;
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error('Tax document migration could not complete.', ['exception' => $exception::class]);
            $this->error('Migration could not complete. Check private storage and database availability.');
            return self::FAILURE;
        }
    }
}
