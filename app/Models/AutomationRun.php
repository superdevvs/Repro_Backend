<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Events\ModelsPruned;

/**
 * Age limit: the daily model:prune deletes finished runs older than
 * messaging.automation_runs_retention_days, together with their steps. Unset (the default)
 * prunes nothing. Ages below MIN_RETENTION_DAYS are raised to it, because old runs also mark
 * scheduled sends as done (ScheduledAutomationDispatcher).
 */
class AutomationRun extends Model
{
    use \App\Models\Concerns\HasSafeAutomationError;
    use HasFactory;
    use MassPrunable;

    public const MIN_RETENTION_DAYS = 30;

    private const FINISHED_STATUSES = ['completed', 'failed', 'cancelled'];

    private const ACTIVE_STEP_STATUSES = ['pending', 'running', 'waiting'];

    // Execution context can contain password-reset links and other action inputs.
    protected $hidden = ['context_json'];

    protected $fillable = [
        'automation_rule_id',
        'trigger_type',
        'status',
        'context_json',
        'related_shoot_id',
        'related_account_id',
        'related_invoice_id',
        'scheduled_for',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'context_json' => 'array',
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class)->orderBy('id');
    }

    public static function retentionDays(): ?int
    {
        $days = config('messaging.automation_runs_retention_days');

        if ($days === null || $days === '' || (int) $days <= 0) {
            return null;
        }

        return max(self::MIN_RETENTION_DAYS, (int) $days);
    }

    public function prunable(): Builder
    {
        $days = static::retentionDays();

        if ($days === null) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()
            ->whereIn('status', self::FINISHED_STATUSES)
            ->where('created_at', '<', now()->subDays($days))
            ->whereDoesntHave('steps', fn (Builder $steps) => $steps->whereIn('status', self::ACTIVE_STEP_STATUSES));
    }

    /**
     * Deletes each chunk's steps and runs in one short transaction. Steps are deleted
     * explicitly so the result does not depend on foreign-key cascades or prune order.
     */
    public function pruneAll(int $chunkSize = 1000): int
    {
        if (static::retentionDays() === null) {
            return 0;
        }

        $total = 0;

        do {
            $ids = $this->prunable()->orderBy('id')->limit($chunkSize)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $this->getConnection()->transaction(function () use ($ids): void {
                AutomationRunStep::query()->whereIn('automation_run_id', $ids)->delete();
                static::query()->whereIn('id', $ids)->delete();
            });

            $total += count($ids);
            event(new ModelsPruned(static::class, $total));
        } while (count($ids) === $chunkSize);

        return $total;
    }
}
