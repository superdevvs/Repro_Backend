<?php

namespace Tests\Feature;

use App\Queue\SqliteDatabaseQueue;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourConfigException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tour flags, caps and timings (spec 1.3, 3.1-3.3). */
class IguideLabTourConfigTest extends TestCase
{
    public function test_defaults_ship_disabled(): void
    {
        $this->assertFalse(LabTourConfig::analysisEnabled());
        $this->assertFalse(LabTourConfig::runsPaused());
        $this->assertFalse(LabTourConfig::grassRunsEnabled());
        $this->assertFalse(LabTourConfig::declutterRunsEnabled());
        $this->assertSame(['studio', 'studio'], [LabTourConfig::queueConnection(), LabTourConfig::queue()]);
        $this->assertSame(8, LabTourConfig::chunkSize());
        $this->assertSame(1, LabTourConfig::workers());
        $shipped = [
            'surveyModel' => 'gpt-6-astra', 'surveyEffort' => 'medium', 'surveyMaxOutputTokens' => 16000, 'surveyMaxResponseBytes' => 524288,
            'surveyMaxOutputTextBytes' => 262144, 'surveyMaxPayloadBytes' => 37748736, 'surveyDeadlineSeconds' => 360, 'viewsTimeoutSeconds' => 90,
            'maxCaptures' => 80, 'surveyCallsPerProject' => 240, 'surveyCallsPerDay' => 160, 'maxPaidSurveysPerCapture' => 2, 'maxRunsPerAnalysis' => 40,
            'maxWorkflowsPerRun' => 10, 'maxConsecutiveChunkFailures' => 2, 'maxNotReadyTicks' => 10, 'workflowStallSeconds' => 900,
            'monitorIdleHours' => 6, 'monitorInterval' => 60, 'heartbeatStaleSeconds' => 300, 'kickGateSeconds' => 60, 'capabilitiesCacheSeconds' => 30,
        ];
        foreach ($shipped as $accessor => $value) $this->assertSame($value, LabTourConfig::$accessor(), $accessor);
        $this->assertSame(['min' => 1, 'max' => 2], LabTourConfig::surveyMinutesPerCapture());
        $this->assertSame(['min' => 3, 'max' => 5], LabTourConfig::editMinutesPerRoom());
        $this->assertSame([900, 900, 300, 5, 600, 3], array_map(fn ($key) => LabTourConfig::staleness($key),
            ['survey_queued_seconds', 'survey_submitting_seconds', 'survey_received_seconds', 'max_survey_dispatches', 'chunk_reserved_seconds', 'max_chunk_start_attempts']));
        $this->assertSame([16384, 4096], [LabTourConfig::bodyLimit('selection'), LabTourConfig::bodyLimit('default')]);
    }

    public function test_flags_are_read_strictly(): void
    {
        foreach (['true', 1, '1', 'yes'] as $loose) {
            config(['iguide-lab-tour.tour_analysis_enabled' => $loose, 'iguide-lab-tour.tour_runs_paused' => $loose]);
            $this->assertFalse(LabTourConfig::analysisEnabled());
            $this->assertFalse(LabTourConfig::runsPaused());
        }
        config(['iguide-lab-tour.tour_analysis_enabled' => true, 'iguide-lab-tour.tour_runs_paused' => true]);
        $this->assertTrue(LabTourConfig::analysisEnabled());
        $this->assertTrue(LabTourConfig::runsPaused());
    }

    public function test_clamps_follow_contract_maxima(): void
    {
        config(['iguide-lab-tour.runs.chunk_size' => 50]);
        $this->assertSame(20, LabTourConfig::chunkSize());
        config(['iguide-lab.tour_scheduler_enabled' => true]);
        $this->assertSame(20, LabTourConfig::chunkSize());
        config(['iguide-lab.tour_scheduler_enabled' => false]);
        $this->assertLessThanOrEqual(\App\Services\IguideLab\LabWorkflowScheduler::maxItems(), LabTourConfig::chunkSize());
        config(['iguide-lab-tour.runs.chunk_size' => 0]);
        $this->assertSame(1, LabTourConfig::chunkSize());
        config(['iguide-lab-tour.caps' => ['max_captures' => 500, 'survey_calls_per_project' => 1000, 'survey_calls_per_day' => 1000,
            'max_paid_surveys_per_capture' => 9, 'max_runs_per_analysis' => 400]]);
        $this->assertSame([80, 240, 160, 3, 40], [LabTourConfig::maxCaptures(), LabTourConfig::surveyCallsPerProject(), LabTourConfig::surveyCallsPerDay(),
            LabTourConfig::maxPaidSurveysPerCapture(), LabTourConfig::maxRunsPerAnalysis()]);
        config(['iguide-lab-tour.caps' => ['max_captures' => 0, 'survey_calls_per_project' => -5, 'survey_calls_per_day' => -1,
            'max_paid_surveys_per_capture' => 0, 'max_runs_per_analysis' => 0]]);
        $this->assertSame([1, 0, 0, 1, 1], [LabTourConfig::maxCaptures(), LabTourConfig::surveyCallsPerProject(), LabTourConfig::surveyCallsPerDay(),
            LabTourConfig::maxPaidSurveysPerCapture(), LabTourConfig::maxRunsPerAnalysis()]);
        foreach ([[0, 1], [99, 8], [3, 3]] as [$in, $out]) {
            config(['iguide-lab-tour.worker_count' => $in]);
            $this->assertSame($out, LabTourConfig::workers());
        }
        config(['iguide-lab-tour.runs.max_workflows_per_run' => 25]);
        $this->assertSame(10, LabTourConfig::maxWorkflowsPerRun());
        config(['iguide-lab-tour.monitor' => ['interval_seconds' => 1, 'heartbeat_stale_seconds' => 10, 'kick_gate_seconds' => 0]]);
        $this->assertSame([15, 120, 15], [LabTourConfig::monitorInterval(), LabTourConfig::heartbeatStaleSeconds(), LabTourConfig::kickGateSeconds()]);
        config(['iguide-lab-tour.monitor' => ['interval_seconds' => 9999, 'heartbeat_stale_seconds' => 99999, 'kick_gate_seconds' => 9999]]);
        $this->assertSame([600, 3600, 600], [LabTourConfig::monitorInterval(), LabTourConfig::heartbeatStaleSeconds(), LabTourConfig::kickGateSeconds()]);
        config(['iguide-lab-tour.runs.workflow_stall_seconds' => 1, 'iguide-lab-tour.runs.monitor_idle_hours' => 0]);
        $this->assertSame([300, 1], [LabTourConfig::workflowStallSeconds(), LabTourConfig::monitorIdleHours()]);
        config(['iguide-lab-tour.runs.workflow_stall_seconds' => 99999, 'iguide-lab-tour.runs.monitor_idle_hours' => 99]);
        $this->assertSame([7200, 48], [LabTourConfig::workflowStallSeconds(), LabTourConfig::monitorIdleHours()]);
        config(['iguide-lab-tour.staleness' => ['survey_queued_seconds' => 5, 'max_survey_dispatches' => 0, 'max_chunk_start_attempts' => 50]]);
        $this->assertSame([60, 1, 10], [LabTourConfig::staleness('survey_queued_seconds'), LabTourConfig::staleness('max_survey_dispatches'),
            LabTourConfig::staleness('max_chunk_start_attempts')]);
        config(['iguide-lab-tour.body_limits' => ['selection_bytes' => 1 << 20, 'default_bytes' => 1 << 20]]);
        $this->assertSame([16384, 16384], [LabTourConfig::bodyLimit('selection'), LabTourConfig::bodyLimit('default')]);
        config(['iguide-lab-tour.survey.max_response_bytes' => 1000]);
        $this->assertSame(524288, LabTourConfig::surveyMaxResponseBytes());
        config(['iguide-lab-tour.survey.max_response_bytes' => 2 << 20]);
        $this->assertSame(2 << 20, LabTourConfig::surveyMaxResponseBytes());
        foreach (['staleness' => 'unknown_seconds', 'bodyLimit' => 'upload'] as $accessor => $key) {
            try {
                LabTourConfig::$accessor($key);
                $this->fail('Expected an unknown key to be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_large_timing_values_are_clamped_below_the_minutes_maximum(): void
    {
        config(['iguide-lab-tour.timing' => ['survey_minutes_per_capture' => ['min' => 1, 'max' => 500], 'edit_minutes_per_room' => ['min' => 3, 'max' => 200]]]);
        $this->assertSame(['min' => 1, 'max' => 125], LabTourConfig::surveyMinutesPerCapture());
        $this->assertSame(['min' => 3, 'max' => 125], LabTourConfig::editMinutesPerRoom());
        config(['iguide-lab-tour.timing' => ['survey_minutes_per_capture' => ['min' => 900, 'max' => 500], 'edit_minutes_per_room' => ['min' => 40, 'max' => 7]]]);
        $this->assertSame(['min' => 125, 'max' => 125], LabTourConfig::surveyMinutesPerCapture());
        $this->assertSame(['min' => 7, 'max' => 7], LabTourConfig::editMinutesPerRoom());
        config(['iguide-lab-tour.timing' => ['survey_minutes_per_capture' => ['min' => -4, 'max' => 'many'], 'edit_minutes_per_room' => 'fast']]);
        $this->assertSame(['min' => 0, 'max' => 2], LabTourConfig::surveyMinutesPerCapture());
        $this->assertSame(['min' => 3, 'max' => 5], LabTourConfig::editMinutesPerRoom());

        config(['iguide-lab-tour.timing' => ['survey_minutes_per_capture' => ['min' => 500, 'max' => 500], 'edit_minutes_per_room' => ['min' => 200, 'max' => 200]],
            'iguide-lab-tour.worker_count' => 1, 'iguide-lab-tour.caps.max_captures' => 80]);
        $rooms = LabTourConfig::maxCaptures();
        $edit = LabTourConfig::editMinutesPerRoom();
        $survey = LabTourConfig::surveyMinutesPerCapture();
        $estimate = ['min' => $rooms * $edit['min'], 'max' => $rooms * $edit['max']];
        $eta = ['min' => (int) ceil($rooms * $survey['min'] / LabTourConfig::workers()), 'max' => (int) ceil($rooms * $survey['max'] / LabTourConfig::workers())];
        foreach ([$estimate, $eta] as $minutes) {
            $this->assertLessThanOrEqual(10000, $minutes['max']);
            $this->assertLessThanOrEqual($minutes['max'], $minutes['min']);
            $this->assertGreaterThanOrEqual(0, $minutes['min']);
        }
    }

    public function test_grass_and_declutter_run_flags_are_hard_wired_off_in_d1(): void
    {
        putenv('IGUIDE_LAB_TOUR_GRASS_RUNS_ENABLED=true');
        putenv('IGUIDE_LAB_TOUR_DECLUTTER_RUNS_ENABLED=true');
        try {
            $file = require config_path('iguide-lab-tour.php');
            $this->assertTrue($file['grass_runs_enabled']);
            $this->assertTrue($file['declutter_runs_enabled']);
        } finally {
            putenv('IGUIDE_LAB_TOUR_GRASS_RUNS_ENABLED');
            putenv('IGUIDE_LAB_TOUR_DECLUTTER_RUNS_ENABLED');
        }
        config(['iguide-lab-tour.grass_runs_enabled' => true, 'iguide-lab-tour.declutter_runs_enabled' => true]);
        $this->assertFalse(LabTourConfig::grassRunsEnabled());
        $this->assertFalse(LabTourConfig::declutterRunsEnabled());
    }

    public function test_tour_queue_connection_is_safe(): void
    {
        $connection = config('queue.connections.iguide-lab-tour');
        $this->assertSame('database', $connection['driver']);
        $this->assertSame('jobs', $connection['table']);
        $this->assertSame('iguide-lab-tour', $connection['queue']);
        $this->assertSame(900, $connection['retry_after']);
        $this->assertGreaterThan(600, $connection['retry_after']);
        $this->assertTrue($connection['after_commit']);
        $this->assertSame(config('queue.connections.studio.connection'), $connection['connection']);
        $this->assertArrayHasKey('studio', config('queue.connections'));
        $this->assertInstanceOf(SqliteDatabaseQueue::class, Queue::connection('iguide-lab-tour'));
        // The studio connection the tour layer uses by default is unchanged.
        $this->assertSame(['driver' => 'database', 'connection' => config('queue.connections.studio.connection'), 'table' => 'jobs',
            'queue' => 'studio', 'retry_after' => 7260, 'after_commit' => true], config('queue.connections.studio'));

        config(['iguide-lab-tour.queue_connection' => 'iguide-lab-tour', 'iguide-lab-tour.queue' => 'iguide-lab-tour']);
        $this->assertSame(['iguide-lab-tour', 'iguide-lab-tour'], [LabTourConfig::queueConnection(), LabTourConfig::queue()]);
    }

    public function test_invalid_queue_settings_fall_back_to_studio_and_log_once(): void
    {
        Log::spy();
        $missing = 'missing-'.Str::random(8);
        config(['iguide-lab-tour.queue_connection' => $missing, 'iguide-lab-tour.queue' => 'iguide-lab-tour']);
        for ($i = 0; $i < 3; $i++) $this->assertSame(['studio', 'studio'], [LabTourConfig::queueConnection(), LabTourConfig::queue()]);
        Log::shouldHaveReceived('warning')->once();
        foreach ([['', 'iguide-lab-tour'], ['iguide-lab-tour', ''], [null, 'studio'], ['studio', ['x']]] as [$connection, $queue]) {
            config(['iguide-lab-tour.queue_connection' => $connection, 'iguide-lab-tour.queue' => $queue]);
            $this->assertSame(['studio', 'studio'], [LabTourConfig::queueConnection(), LabTourConfig::queue()]);
        }
    }

    public function test_non_database_queue_connection_falls_back_to_studio(): void
    {
        // Deploy 1.1: a connection that exists but is not a database queue (sync in .env.example) would run paid surveys
        // inline and turn off the pending-job guards (LabTourQueue reads the jobs table), so it is refused like a typo.
        $this->assertSame('sync', config('queue.connections.sync.driver'));
        Log::spy();
        config(['iguide-lab-tour.queue_connection' => 'sync', 'iguide-lab-tour.queue' => 'default']);
        for ($i = 0; $i < 3; $i++) $this->assertSame(['studio', 'studio'], [LabTourConfig::queueConnection(), LabTourConfig::queue()]);
        Log::shouldHaveReceived('warning')->once();
        $this->assertSame(['connection' => 'studio', 'queue' => 'studio', 'workers' => 1, 'dedicated' => false], $this->tourWorkers());

        // The dedicated target is still accepted while its connection uses the database driver ...
        config(['iguide-lab-tour.queue_connection' => 'iguide-lab-tour', 'iguide-lab-tour.queue' => 'iguide-lab-tour']);
        $this->assertSame(['iguide-lab-tour', 'iguide-lab-tour'], [LabTourConfig::queueConnection(), LabTourConfig::queue()]);
        $this->assertSame(['connection' => 'iguide-lab-tour', 'queue' => 'iguide-lab-tour', 'workers' => 1, 'dedicated' => true], $this->tourWorkers());

        // ... and refused when that connection is pointed at another driver.
        config(['queue.connections.iguide-lab-tour.driver' => 'sync']);
        $this->assertSame(['studio', 'studio'], [LabTourConfig::queueConnection(), LabTourConfig::queue()]);
        $this->assertSame(['connection' => 'studio', 'queue' => 'studio', 'workers' => 1, 'dedicated' => false], $this->tourWorkers());
    }

    /** @return array<string, mixed> the one JSON line of iguide-lab:tour-workers */
    private function tourWorkers(): array
    {
        $this->assertSame(0, Artisan::call('iguide-lab:tour-workers'));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_survey_settings_fail_closed(): void
    {
        foreach ([['survey.model', 'gpt-5'], ['survey.model', ''], ['survey.effort', 'xhigh'], ['survey.max_output_tokens', 3999],
            ['survey.max_output_tokens', 32001], ['survey.max_output_tokens', '16000']] as [$key, $value]) {
            config(['iguide-lab-tour.'.$key => $value]);
            try {
                LabTourConfig::surveyModel();
                LabTourConfig::surveyEffort();
                LabTourConfig::surveyMaxOutputTokens();
                $this->fail("Expected {$key} to be rejected.");
            } catch (LabTourConfigException $e) {
                $this->assertSame('survey_misconfigured', $e->reasonCode);
                $this->assertSame('survey_misconfigured', $e->getMessage());
            }
            config(['iguide-lab-tour.survey' => ['model' => 'gpt-6-astra', 'effort' => 'medium', 'max_output_tokens' => 16000]]);
        }
        foreach (['low', 'medium', 'high'] as $effort) {
            config(['iguide-lab-tour.survey.effort' => $effort]);
            $this->assertSame($effort, LabTourConfig::surveyEffort());
        }
        foreach ([4000, 32000] as $tokens) {
            config(['iguide-lab-tour.survey.max_output_tokens' => $tokens]);
            $this->assertSame($tokens, LabTourConfig::surveyMaxOutputTokens());
        }
    }
}
