<?php
namespace App\Services\IguideLab;

use Illuminate\Support\Facades\Log;

/**
 * Typed, clamped accessors for config/iguide-lab-tour.php (spec 3.2). Always config(), never env(): production
 * config is cached. Flags are strict (`=== true`, the style of LabWorkflowScheduler.php:15). Clamps follow the
 * contract readers (tourContract.ts) so a mistyped .env value cannot make a DTO unreadable.
 */
final class LabTourConfig
{
    public const SURVEY_MODELS = ['gpt-6-astra'];
    public const SURVEY_EFFORTS = ['low', 'medium', 'high'];
    /** floor(10000 / 80): every minute total multiplies by at most 80 rooms or captures (MINUTES max, tourContract.ts:83). */
    public const MAX_MINUTES_PER_UNIT = 125;
    private const STALENESS = ['survey_queued_seconds', 'survey_submitting_seconds', 'survey_received_seconds', 'max_survey_dispatches',
        'chunk_reserved_seconds', 'max_chunk_start_attempts'];
    private const BODY_LIMITS = ['selection' => 'selection_bytes', 'default' => 'default_bytes'];

    /** @var array<string, true> invalid queue settings already logged by this process */
    private static array $queueWarnings = [];

    public static function analysisEnabled(): bool { return config('iguide-lab-tour.tour_analysis_enabled', false) === true; }
    public static function runsPaused(): bool { return config('iguide-lab-tour.tour_runs_paused', false) === true; }

    /** D1 runs lighting presets only (D1; LabGrassWorkflow.php:42-44). D3 replaces this body together with its driver. */
    public static function grassRunsEnabled(): bool { return false; }

    /** D1 runs lighting presets only (D1; LabDeclutterWorkflow.php:44,47). D5 replaces this body together with its driver. */
    public static function declutterRunsEnabled(): bool { return false; }

    public static function surveyModel(): string
    {
        $model = config('iguide-lab-tour.survey.model');
        if (! in_array($model, self::SURVEY_MODELS, true)) throw new LabTourConfigException('survey_misconfigured');

        return $model;
    }

    public static function surveyEffort(): string
    {
        $effort = config('iguide-lab-tour.survey.effort');
        if (! in_array($effort, self::SURVEY_EFFORTS, true)) throw new LabTourConfigException('survey_misconfigured');

        return $effort;
    }

    public static function surveyMaxOutputTokens(): int
    {
        $tokens = config('iguide-lab-tour.survey.max_output_tokens');
        if (! is_int($tokens) || $tokens < 4000 || $tokens > 32000) throw new LabTourConfigException('survey_misconfigured');

        return $tokens;
    }

    /** D6: never below 512 KiB; the body is stored before this check, so raising it and re-parsing recovers a large answer. */
    public static function surveyMaxResponseBytes(): int { return max(524288, self::int('survey.max_response_bytes', 524288, 524288, PHP_INT_MAX)); }
    /** Not clamped by the spec; bounded by the 8 MiB receipt file read (spec 5.8 step 1). */
    public static function surveyMaxOutputTextBytes(): int { return self::int('survey.max_output_text_bytes', 262144, 1024, 8388608); }
    /** Not clamped by the spec; at most 36 MiB (spec 5.5). */
    public static function surveyMaxPayloadBytes(): int { return self::int('survey.max_payload_bytes', 37748736, 1048576, 37748736); }
    /** Not clamped by the spec; deadline plus views timeout stay below the 600 s survey job timeout. */
    public static function surveyDeadlineSeconds(): int { return self::int('survey.deadline_seconds', 360, 60, 420); }
    public static function viewsTimeoutSeconds(): int { return self::int('survey.views_timeout_seconds', 90, 10, 120); }

    public static function maxCaptures(): int { return self::int('caps.max_captures', 80, 1, 80); }
    public static function surveyCallsPerProject(): int { return self::int('caps.survey_calls_per_project', 240, 0, 240); }
    public static function surveyCallsPerDay(): int { return self::int('caps.survey_calls_per_day', 160, 0, 160); }
    public static function maxPaidSurveysPerCapture(): int { return self::int('caps.max_paid_surveys_per_capture', 2, 1, 3); }
    public static function maxRunsPerAnalysis(): int { return self::int('caps.max_runs_per_analysis', 40, 1, 40); }

    /** D3: 1..20 and never above the workflow scheduler's own item cap (LabWorkflowScheduler.php:16). */
    public static function chunkSize(): int { return self::int('runs.chunk_size', 8, 1, min(20, LabWorkflowScheduler::maxItems())); }
    public static function maxWorkflowsPerRun(): int { return self::int('runs.max_workflows_per_run', 10, 1, 10); }
    public static function maxConsecutiveChunkFailures(): int { return self::int('runs.max_consecutive_chunk_failures', 2, 1, 10); }
    public static function maxNotReadyTicks(): int { return self::int('runs.max_not_ready_ticks', 10, 1, 100); }
    public static function workflowStallSeconds(): int { return self::int('runs.workflow_stall_seconds', 900, 300, 7200); }
    public static function monitorIdleHours(): int { return self::int('runs.monitor_idle_hours', 6, 1, 48); }

    public static function queueConnection(): string { return self::queueTarget()[0]; }
    public static function queue(): string { return self::queueTarget()[1]; }
    public static function workers(): int { return self::int('worker_count', 1, 1, 8); }

    /** @return array{min:int,max:int} each 0..125 */
    public static function surveyMinutesPerCapture(): array { return self::minutes('survey_minutes_per_capture', 1, 2); }
    /** @return array{min:int,max:int} each 0..125 */
    public static function editMinutesPerRoom(): array { return self::minutes('edit_minutes_per_room', 3, 5); }

    public static function monitorInterval(): int { return self::int('monitor.interval_seconds', 60, 15, 600); }
    public static function heartbeatStaleSeconds(): int { return self::int('monitor.heartbeat_stale_seconds', 300, 120, 3600); }
    public static function kickGateSeconds(): int { return self::int('monitor.kick_gate_seconds', 60, 15, 600); }

    /** Seconds keys are at least 60; max_* keys are 1..10. */
    public static function staleness(string $key): int
    {
        if (! in_array($key, self::STALENESS, true)) throw new \InvalidArgumentException('Unknown tour staleness key.');
        $default = ['survey_queued_seconds' => 900, 'survey_submitting_seconds' => 900, 'survey_received_seconds' => 300,
            'max_survey_dispatches' => 5, 'chunk_reserved_seconds' => 600, 'max_chunk_start_attempts' => 3][$key];

        return str_starts_with($key, 'max_') ? self::int('staleness.'.$key, $default, 1, 10) : self::int('staleness.'.$key, $default, 60, PHP_INT_MAX);
    }

    public static function capabilitiesCacheSeconds(): int { return self::int('capabilities_cache_seconds', 30, 1, 300); }

    /** 'selection' (16 KiB) or 'default' (4 KiB); never above the bridge's 16 KiB JSON cap. */
    public static function bodyLimit(string $kind): int
    {
        if (! isset(self::BODY_LIMITS[$kind])) throw new \InvalidArgumentException('Unknown tour body limit.');

        return self::int('body_limits.'.self::BODY_LIMITS[$kind], $kind === 'selection' ? 16384 : 4096, 1024, 16384);
    }

    /**
     * Both values must be non-empty strings and the connection must exist and use the database driver (sync would run
     * paid surveys inline, and the pending-job guards of LabTourQueue read the jobs table); otherwise the pair falls back
     * to studio/studio (today's single worker, scripts/install-studio-runtime.sh:96) and the problem is logged once.
     *
     * @return array{0:string,1:string}
     */
    private static function queueTarget(): array
    {
        $connection = config('iguide-lab-tour.queue_connection');
        $queue = config('iguide-lab-tour.queue');
        if (is_string($connection) && $connection !== '' && is_string($queue) && $queue !== ''
            && is_array(config('queue.connections.'.$connection)) && config('queue.connections.'.$connection.'.driver') === 'database') {
            return [$connection, $queue];
        }
        $key = json_encode([$connection, $queue]);
        if (! isset(self::$queueWarnings[$key])) {
            self::$queueWarnings[$key] = true;
            Log::warning('Tour queue settings are invalid; using the studio connection and queue.', [
                'connection' => is_string($connection) ? $connection : get_debug_type($connection),
                'queue' => is_string($queue) ? $queue : get_debug_type($queue),
            ]);
        }

        return ['studio', 'studio'];
    }

    /** @return array{min:int,max:int} */
    private static function minutes(string $key, int $defaultMin, int $defaultMax): array
    {
        $value = config('iguide-lab-tour.timing.'.$key);
        $value = is_array($value) ? $value : [];
        $min = self::clampInt($value['min'] ?? null, $defaultMin, 0, self::MAX_MINUTES_PER_UNIT);
        $max = self::clampInt($value['max'] ?? null, $defaultMax, 0, self::MAX_MINUTES_PER_UNIT);

        return ['min' => min($min, $max), 'max' => $max];
    }

    private static function int(string $key, int $default, int $min, int $max): int
    {
        return self::clampInt(config('iguide-lab-tour.'.$key), $default, $min, $max);
    }

    /** Integers (or integer strings) are clamped to [min, max]; anything else uses the shipped default. */
    private static function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/\A-?\d{1,18}\z/D', $value)) $value = (int) $value;
        if (! is_int($value)) $value = $default;

        return max($min, min($max, $value));
    }
}
