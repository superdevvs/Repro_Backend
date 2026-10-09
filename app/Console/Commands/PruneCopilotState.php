<?php

namespace App\Console\Commands;

use App\Support\LockedWrite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneCopilotState extends Command
{
    protected $signature = 'copilot:prune';

    protected $description = 'Prune expired OAuth requests and old resolved Copilot state; preserve uncertain actions.';

    public function handle(): int
    {
        $queries = [
            DB::table('copilot_auth_requests')->where('expires_at', '<', now()->subDay()),
            DB::table('copilot_tokens')->where('refresh_expires_at', '<', now()->subDays(90)),
            DB::table('copilot_drafts')->whereIn('status', ['prepared', 'completed'])->where('expires_at', '<', now()->subDays(90)),
            DB::table('copilot_watch_events')->where('created_at', '<', now()->subDays(90)),
            DB::table('copilot_clients')->where('created_at', '<', now()->subDays(7))
                ->whereNotIn('id', DB::table('copilot_grants')->select('client_id'))
                ->whereNotIn('id', DB::table('copilot_auth_requests')->select('client_id')),
        ];
        foreach ($queries as $query) {
            do {
                $count = LockedWrite::run(function () use ($query) {
                    $ids = (clone $query)->limit(500)->pluck('id');

                    return $ids->isEmpty() ? 0 : (clone $query)->whereIn('id', $ids)->delete();
                }, 'copilot-prune');
            } while ($count === 500);
        }
        $this->info('Expired Copilot state pruned; uncertain operations retained.');

        return self::SUCCESS;
    }
}
