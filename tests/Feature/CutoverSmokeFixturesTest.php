<?php

namespace Tests\Feature;

use App\Models\AryeoConnection;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CutoverTableSnapshots;
use Tests\TestCase;

/**
 * cutover:smoke-fixtures --create (design v2, 5.3): one inert test client and
 * one inert test shoot that the MySQL cutover smoke reads and edits.
 */
class CutoverSmokeFixturesTest extends TestCase
{
    use CutoverTableSnapshots;
    use RefreshDatabase;

    private const WORKER_TOKEN = 'cutover_smoke_fixture_worker_token_0123456789';

    /** Columns that would link a shoot or a client to a provider, a person or a schedule. */
    private const PROVIDER_COLUMN = '/cubicasa|iguide|mls|mmm_|dropbox|stripe|aryeo|google|copilot|external|tour_|featured|bright|photographer_id|editor_id|rep_id|scheduled|phone|cc_emails|verified_by|approved_by|idempotency/';

    public function test_fixtures_have_no_provider_ids_and_one_encrypted_field(): void
    {
        $before = $this->tableSnapshot();
        $counts = ['users' => DB::table('users')->count(), 'shoots' => DB::table('shoots')->count()];
        $cursor = (int) DB::table('aryeo_changes')->max('id');

        [$exit, $out, $raw] = $this->fixtures('--create');

        $this->assertSame(0, $exit, $raw);
        $this->assertTrue($out['ok'], $raw);
        $this->assertTrue($out['created']);
        $this->assertSame(['aryeo_changes', 'shoots', 'users'], $this->changedTables($before, $this->tableSnapshot()));

        $client = DB::table('users')->find($out['client_id']);
        $shoot = DB::table('shoots')->find($out['shoot_id']);
        $this->assertSame((int) $client->id, (int) $shoot->client_id);
        $this->assertSame(['users' => $counts['users'] + 1, 'shoots' => $counts['shoots'] + 1],
            ['users' => DB::table('users')->count(), 'shoots' => DB::table('shoots')->count()]);
        $this->assertSame([['shoot_id' => (int) $shoot->id, 'client_id' => (int) $client->id, 'kind' => 'shoots.INSERT']],
            DB::table('aryeo_changes')->where('id', '>', $cursor)->get(['shoot_id', 'client_id', 'kind'])
                ->map(fn ($row) => ['shoot_id' => (int) $row->shoot_id, 'client_id' => (int) $row->client_id, 'kind' => $row->kind])->all());

        $this->assertSame('client', $client->role);
        $this->assertSame('inactive', $client->account_status);
        $this->assertStringEndsWith('.invalid', $client->email);
        $this->assertSame(Shoot::SHOOT_TYPE_INTERNAL_TEST, $shoot->shoot_type);
        $this->assertSame('cancelled', $shoot->status);
        $this->assertSame('cancelled', $shoot->workflow_status);
        $this->assertSame(Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED, $shoot->payment_status);
        $this->assertEquals(0, $shoot->total_quote);

        foreach (['users' => $client, 'shoots' => $shoot] as $table => $row) {
            $linked = array_filter(Schema::getColumnListing($table), fn ($column) => preg_match(self::PROVIDER_COLUMN, $column));
            $this->assertNotEmpty($linked, $table);
            foreach ($linked as $column) {
                $this->assertContains($row->{$column}, [null, '0', 0, '[]'], "$table.$column must stay empty");
            }
        }

        $encrypted = [];
        foreach ([User::class => $client->id, Shoot::class => $shoot->id] as $model => $id) {
            $record = $model::query()->withoutGlobalScopes()->findOrFail($id);
            foreach ($record->getCasts() as $attribute => $cast) {
                if (str_starts_with($cast, 'encrypted') && $record->getRawOriginal($attribute) !== null) {
                    $encrypted[] = $attribute;
                    $this->assertSame('repro-cutover-smoke-v1', $record->{$attribute});
                }
            }
        }
        $this->assertSame(['two_factor_secret'], $encrypted);
        $this->assertNull(User::find($client->id)->two_factor_confirmed_at);
    }

    public function test_aryeo_agent_ignores_fixture_changes(): void
    {
        $linkedClient = User::factory()->create(['role' => 'client']);
        $linkedShoot = Shoot::factory()->create(['client_id' => $linkedClient->id]);
        AryeoConnection::create(['name' => 'Smoke Mac', 'company_key' => 'repro', 'client_ids' => [$linkedClient->id],
            'token_hash' => hash('sha256', self::WORKER_TOKEN), 'processing_enabled' => true, 'discover_all_clients' => true,
            'delivery_shoot_ids' => [$linkedShoot->id]]);
        $cursor = (int) DB::table('aryeo_changes')->max('id');

        [$exit, $out, $raw] = $this->fixtures('--create');
        $this->assertSame(0, $exit, $raw);
        // The edit the write smoke makes, and one to the linked shoot as a control.
        DB::table('shoots')->where('id', $out['shoot_id'])->update(['company_notes' => 'cutover smoke edit']);
        DB::table('shoots')->where('id', $linkedShoot->id)->update(['company_notes' => 'control edit']);

        $this->assertSame(2, DB::table('aryeo_changes')->where('id', '>', $cursor)->where('shoot_id', $out['shoot_id'])->count(),
            'the shoot triggers did write change rows for the fixture');

        $feed = $this->worker()->getJson('/api/integrations/aryeo/v1/changes?cursor='.$cursor)->assertOk()->json('data');
        $this->assertNotEmpty($feed);
        $this->assertSame([$linkedShoot->id], array_values(array_unique(array_column($feed, 'shoot_id'))));
        $this->assertNotContains($out['client_id'], array_column($feed, 'client_id'));

        $listed = $this->worker()->getJson('/api/integrations/aryeo/v1/shoots')->assertOk()->json('data');
        $this->assertNotContains($out['shoot_id'], array_column($listed, 'id'));
        $this->worker()->getJson('/api/integrations/aryeo/v1/shoots/'.$out['shoot_id'])->assertNotFound();
        $this->worker()->getJson('/api/integrations/aryeo/v1/shoots/'.$out['shoot_id'].'/readiness')->assertNotFound();
    }

    public function test_create_is_idempotent(): void
    {
        [, $first] = $this->fixtures('--create');
        $before = $this->tableSnapshot();

        [$exit, $second, $raw] = $this->fixtures('--create');

        $this->assertSame(0, $exit, $raw);
        $this->assertFalse($second['created']);
        $this->assertSame([$first['client_id'], $first['shoot_id']], [$second['client_id'], $second['shoot_id']]);
        $this->assertSame([], $this->changedTables($before, $this->tableSnapshot()));
    }

    public function test_without_create_reports_and_writes_nothing(): void
    {
        $before = $this->tableSnapshot();
        [$exit, $absent] = $this->fixtures();
        $this->assertSame(1, $exit);
        $this->assertFalse($absent['present']);
        $this->assertSame([], $this->changedTables($before, $this->tableSnapshot()));

        [, $created] = $this->fixtures('--create');
        $before = $this->tableSnapshot();
        [$exit, $present, $raw] = $this->fixtures();
        $this->assertSame(0, $exit, $raw);
        $this->assertTrue($present['present']);
        $this->assertSame($created['shoot_id'], $present['shoot_id']);
        $this->assertSame([], $this->changedTables($before, $this->tableSnapshot()));
    }

    public function test_create_refuses_a_client_already_in_aryeo_scope(): void
    {
        // Every id the new client could get is in an Aryeo connection's scope.
        AryeoConnection::create(['name' => 'Wide Mac', 'company_key' => 'repro', 'client_ids' => range(1, 500),
            'token_hash' => hash('sha256', self::WORKER_TOKEN)]);
        $before = $this->tableSnapshot();

        [$exit, $out, $raw] = $this->fixtures('--create');

        $this->assertSame(1, $exit, $raw);
        $this->assertFalse($out['ok']);
        $this->assertFalse($out['created']);
        $this->assertStringContainsString('Aryeo', implode(' ', $out['problems']));
        $this->assertSame([], $this->changedTables($before, $this->tableSnapshot()), 'the refused create rolled back');
    }

    public function test_existing_fixtures_that_lost_inertness_are_reported(): void
    {
        [, $created] = $this->fixtures('--create');
        DB::table('shoots')->where('id', $created['shoot_id'])->update(['cubicasa_order_id' => 'CC-123']);

        [$exit, $out] = $this->fixtures('--create');

        $this->assertSame(1, $exit);
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('cubicasa_order_id', implode(' ', $out['problems']));
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function fixtures(string ...$options): array
    {
        $arguments = ['--json' => true];
        foreach ($options as $option) {
            $arguments[$option] = true;
        }
        $exit = Artisan::call('cutover:smoke-fixtures', $arguments);
        $raw = Artisan::output();

        return [$exit, json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $raw];
    }

    private function worker(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.self::WORKER_TOKEN);
    }
}
