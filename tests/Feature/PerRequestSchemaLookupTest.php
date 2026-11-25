<?php

namespace Tests\Feature;

use App\Events\SystemOverviewActivityUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatedSecurityTestCase;

/**
 * The code that runs on every signed-in API request (account checks, the email-verification
 * pilot, request telemetry) asks whether tables and columns exist. With the schema cache on, a
 * request after the first one must not send any of those questions to the database.
 */
class PerRequestSchemaLookupTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_request_asks_no_schema_questions_once_the_cache_is_warm(): void
    {
        Http::fake();
        Event::fake([SystemOverviewActivityUpdated::class]);
        config(['database.schema_cache.enabled' => true, 'database.schema_cache.store' => 'array']);
        $this->startFreshPhpProcess();
        Sanctum::actingAs(User::factory()->create(['about' => 'Warm cache probe']));

        $this->getJson('/api/user', ['X-System-Session-Id' => 'schema-warm'])->assertOk();

        $this->startFreshPhpProcess();
        $schemaQueries = [];
        DB::listen(function ($query) use (&$schemaQueries): void {
            if (preg_match('/sqlite_master|pragma_table|information_schema/i', $query->sql)) {
                $schemaQueries[] = $query->sql;
            }
        });

        $this->getJson('/api/user', ['X-System-Session-Id' => 'schema-warm'])->assertOk();

        $this->assertSame([], $schemaQueries);
    }

    /** A PHP-FPM request starts with empty static memos; only the shared cache survives. */
    private function startFreshPhpProcess(): void
    {
        (new \ReflectionProperty(User::class, 'usersTableColumnCache'))->setValue(null, []);
    }
}
