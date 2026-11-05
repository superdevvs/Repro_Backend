<?php

namespace App\Services\Shoots;

/** SQL counterpart of the lifecycle predicate for pagination, counts and dates. */
class ActiveWorkQuery
{
    public static function openSql(): string
    {
        return '(shoots.active_work_started_at IS NOT NULL AND shoots.active_work_completed_at IS NULL'
            .' AND (COALESCE(shoots.active_work_is_reopened, 0) = 1 OR (shoots.active_work_is_reopened IS NULL'
            .' AND (shoots.admin_verified_at IS NOT NULL OR shoots.completed_at IS NOT NULL))))';
    }

    public static function closedSql(): string { return 'NOT '.self::openSql(); }

    public static function matches(object $row): bool
    {
        return ! empty($row->active_work_started_at) && empty($row->active_work_completed_at)
            && ($row->active_work_is_reopened ?? (! empty($row->admin_verified_at) || ! empty($row->completed_at)));
    }
}
