<?php

namespace Tests\MySql;

use App\Models\Shoot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StrictDateStorageTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidDates(): array
    {
        return ['blank timestamp' => ['admin_verified_at', ''], 'malformed timestamp' => ['admin_verified_at', 'malformed'],
            'invalid timestamp date' => ['active_work_completed_at', '2026-02-30 12:00:00'],
            'invalid calendar date' => ['scheduled_date', '2026-02-30']];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_are_rejected_by_the_migrated_typed_schema(string $column, string $value): void
    {
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create());
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SQLSTATE[22007]');
        DB::table('shoots')->where('id', $shoot->id)->update([$column => $value]);
    }
}
