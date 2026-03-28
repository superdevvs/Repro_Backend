<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\SystemOverviewTelemetryService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('system-overview:prune', function (SystemOverviewTelemetryService $telemetry) {
    $result = $telemetry->prune();
    $this->info('System overview telemetry pruned.');
    foreach ($result as $table => $count) {
        $this->line(sprintf('%s: %d', $table, $count));
    }
})->purpose('Prune system overview telemetry older than 24 hours');
