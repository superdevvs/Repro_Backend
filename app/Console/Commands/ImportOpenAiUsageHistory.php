<?php

namespace App\Console\Commands;

use App\Support\LockedWrite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Aggregate minimums from the read-only RePro production audit of 2026-10-10. */
class ImportOpenAiUsageHistory extends Command
{
    protected $signature = 'ai-usage:import-history';
    protected $description = 'Import audited October 1-10 OpenAI minimums without inventing tokens or costs';

    public function handle(): int
    {
        $rows = [
            ['2026-10-01', 'photo_classification', 62], ['2026-10-02', 'photo_classification', 52],
            ['2026-10-03', 'photo_classification', 58], ['2026-10-02', 'robbie_chat', 1], ['2026-10-06', 'robbie_chat', 4],
        ];
        foreach ($rows as [$day, $feature, $calls]) {
            LockedWrite::run(fn () => DB::table('ai_provider_usage')->insertOrIgnore([
                'id' => 'audit-20261010-'.$day.'-'.$feature, 'occurred_at' => $day.' 00:00:00',
                'provider' => 'openai', 'model' => 'gpt-4o', 'endpoint' => 'chat/completions',
                'feature' => $feature, 'source' => 'historical_minimum', 'status' => 'success', 'calls' => $calls,
            ]), 'ai-usage.history');
        }
        $this->info('Historical minimums imported idempotently: 177 calls. Tokens and cost remain unknown.');
        return self::SUCCESS;
    }
}
