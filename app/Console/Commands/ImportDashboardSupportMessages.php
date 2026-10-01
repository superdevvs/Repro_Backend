<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\SupportTicketMessage;
use App\Services\SupportLegacyMessageImporter;
use Illuminate\Console\Command;

class ImportDashboardSupportMessages extends Command
{
    protected $signature = 'support:import-dashboard-messages {--apply : Import eligible dashboard messages without sends or broadcasts}';

    protected $description = 'Preserve legacy dashboard contact conversations in the private Support inbox';

    public function handle(SupportLegacyMessageImporter $importer): int
    {
        $query = Message::where('channel', 'EMAIL')->where('provider', 'INTERNAL')->where('send_source', 'MANUAL')
            ->whereNotIn('id', SupportTicketMessage::whereNotNull('source_message_id')->select('source_message_id'));
        $this->info('Unmapped dashboard message candidates: '.$query->count());
        if (! $this->option('apply')) {
            $this->info('Preview only. No records changed. Eligibility and explicit reply ownership are checked during apply.');

            return self::SUCCESS;
        }
        $imported = 0;
        $skipped = 0;
        $query->orderBy('id')->chunkById(100, function ($messages) use ($importer, &$imported, &$skipped) {
            foreach ($messages as $message) {
                $importer->import($message) ? $imported++ : $skipped++;
            }
        });
        $this->info("Imported: {$imported}; skipped ambiguous or unrelated messages: {$skipped}. Originals unchanged. No notifications sent.");

        return self::SUCCESS;
    }
}
