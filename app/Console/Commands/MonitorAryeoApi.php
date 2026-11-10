<?php

namespace App\Console\Commands;

use App\Models\AryeoApiCase;
use App\Models\AryeoApiExport;
use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorAryeoApi extends Command
{
    protected $signature = 'aryeo:monitor-api';

    protected $description = 'Record in-app Aryeo recovery and executor alerts; never mutate provider state';

    public function handle(): int
    {
        foreach (\App\Models\AryeoApiOperation::where('status', 'sent')->where('sent_at', '<', now()->subMinutes(2))->get() as $op) {
            if ($e = AryeoApiExport::find($op->export_id)) {
                app(\App\Services\Aryeo\Api\Operations::class)->unknown($e, $op, 'executor_interrupted');
            }
        }
        foreach (AryeoApiCase::where('status', 'open')->where('escalate_at', '<=', now())->get() as $case) {
            $this->recordAlert('escalate:'.$case->id, $case->export_id, $case->owner, 'Partner escalation is due. Prepare the sanitized support packet.');
        }
        foreach (AryeoApiExport::whereIn('status', ['running', 'queued'])->where('updated_at', '<', now()->subMinutes(5))->get() as $e) {
            $this->recordAlert('stalled:'.$e->id, $e->id, 'Shubham', 'Export stalled. Stop the old executor and reconcile before resuming.');
        }
        foreach (AryeoConnection::all() as $connection) {
            $ready = $connection->enabled && $connection->processing_enabled && $connection->last_seen_at?->gt(now()->subMinutes(2))
                && data_get($connection->capabilities, 'shoot_executor') === true && data_get($connection->capabilities, 'processing_mode') === 'dashboard_jobs';
            if ($ready) {
                continue;
            }
            foreach (AryeoJob::where('connection_id', $connection->id)->where('status', 'queued')->where('created_at', '<=', now()->subMinutes(2))->get() as $job) {
                $this->recordAlert('bridge:'.$job->id, null, config('aryeo_api.primary_operator'), 'Waiting for Showcase executor. Job '.$job->id.' needs an available, qualified operator.');
            }
        }
        DB::table('aryeo_api_oauth_states')->where('expires_at', '<', now()->subDay())->delete();
        foreach (\App\Models\AryeoApiAsset::where('grant_revoked_at', '<', now()->subDays(7))
            ->whereHas('export', fn ($q) => $q->whereIn('status', ['cancelled', 'delivered']))->limit(100)->get() as $asset) {
            $path = app(\App\Services\Aryeo\Api\FrozenOriginals::class)->path($asset);
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->info('Aryeo in-app operating alerts refreshed.');

        return self::SUCCESS;
    }

    private function recordAlert(string $key, ?string $export, string $owner, string $message): void
    {
        DB::table('aryeo_api_alerts')->insertOrIgnore(['key' => $key, 'export_id' => $export, 'owner' => $owner, 'message' => $message, 'created_at' => now()]);
    }
}
