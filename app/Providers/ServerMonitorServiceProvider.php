<?php

namespace App\Providers;

use App\Services\ServerMonitor\Telemetry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ServerMonitorServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->singleton(Telemetry::class); }

    public function boot(): void
    {
        if (!config('server-monitor.enabled')) return;
        $emit = static function (string $kind, array $fields = []): void {
            try { app(Telemetry::class)->emit($kind, $fields); } catch (\Throwable) {}
        };
        Event::listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function ($event) use ($emit) {
            try {
                $request = $event->request;
                if ($request->is('api/admin/system-overview/server/*')) return;
                $start = $request->attributes->get('monitor_started');
                $route = $request->route()?->uri() ?? 'unmatched';
                $emit('request', ['route' => substr($route, 0, 150), 'method' => $request->method(),
                    'traceId' => \App\Services\RequestCorrelation::id($request), 'status' => $event->response->getStatusCode(), 'durationMs' => $start ? (hrtime(true) - $start) / 1e6 : 0]);
            } catch (\Throwable) {}
        });
        $starts = [];
        foreach ([\Illuminate\Queue\Events\JobProcessing::class => 'started', \Illuminate\Queue\Events\JobProcessed::class => 'success', \Illuminate\Queue\Events\JobFailed::class => 'failed', \Illuminate\Queue\Events\JobExceptionOccurred::class => 'retry'] as $type => $outcome) {
            Event::listen($type, function ($event) use ($emit, $outcome, &$starts) {
                try {
                    $id = $event->job->getJobId() ?? 'sync';
                    if ($outcome === 'started') { if (count($starts) > 100) $starts = []; $starts[$id] = hrtime(true); }
                    $emit('job', ['name' => substr(class_basename($event->job->resolveName()), 0, 100), 'queue' => substr($event->job->getQueue() ?? 'default', 0, 50),
                        'outcome' => $outcome, 'durationMs' => isset($starts[$id]) ? (hrtime(true) - $starts[$id]) / 1e6 : 0]);
                    if ($outcome !== 'started') unset($starts[$id]);
                } catch (\Throwable) {}
            });
        }
        foreach ([\Illuminate\Console\Events\ScheduledTaskStarting::class => 'started', \Illuminate\Console\Events\ScheduledTaskFinished::class => 'success', \Illuminate\Console\Events\ScheduledBackgroundTaskFinished::class => 'success', \Illuminate\Console\Events\ScheduledTaskFailed::class => 'failed', \Illuminate\Console\Events\ScheduledTaskSkipped::class => 'skipped'] as $type => $outcome) {
            Event::listen($type, function ($event) use ($emit, $outcome) {
                try {
                    if ($event instanceof \Illuminate\Console\Events\ScheduledTaskFinished && $event->task->runInBackground) return;
                    $result = $outcome === 'success' && $event->task->exitCode !== 0 ? 'failed' : $outcome;
                    $emit('schedule', ['name' => app(Telemetry::class)->scheduleId($event->task), 'outcome' => $result, 'durationMs' => isset($event->runtime) ? $event->runtime * 1000 : null]);
                } catch (\Throwable) {}
            });
        }
        Event::listen(\Illuminate\Console\Events\CommandStarting::class, function ($event) use ($emit) {
            if ($event->command !== 'schedule:run') return;
            $emit('heartbeat');
            try { app(Telemetry::class)->catalog(); } catch (\Throwable) {}
        });
        Event::listen(\Illuminate\Http\Client\Events\ResponseReceived::class, function ($event) use ($emit) {
            try {
                $host = parse_url($event->request->url(), PHP_URL_HOST);
                $known = ['api.openai.com','api.x.ai','api.stripe.com','api.telnyx.com','api.cubicasa.com','api.iguide.com','api.fal.ai','api.autoenhance.ai','api.resend.com','api.cakemail.com'];
                $emit('integration', ['name' => in_array($host, $known, true) ? $host : 'other', 'status' => $event->response->status(), 'durationMs' => isset($event->response->handlerStats()['total_time']) ? $event->response->handlerStats()['total_time'] * 1000 : null]);
            } catch (\Throwable) {}
        });
        Event::listen(\Illuminate\Http\Client\Events\ConnectionFailed::class, function ($event) use ($emit) {
            $emit('integration', ['name' => 'connection-failure', 'status' => 599]);
        });
    }
}

