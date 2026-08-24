<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\BracketModeResolver;
use App\Services\Shoots\UploadIntakeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The three bracket columns must be safe to roll forward and back.
 *
 * All three are additive and nullable or defaulted, so old code keeps running
 * against the new schema and new code keeps running against un-backfilled rows.
 * The pinning backfill is the part that carries risk: it decides which existing
 * assignments get their divisor frozen, and getting that wrong would silently
 * renumber stacks that already exist.
 */
class BracketModeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function service(
        string $name,
        int $photoCount,
        bool $usesHdrBrackets,
        string $intake = Service::INTAKE_PHOTO,
    ): Service {
        $category = Category::query()->firstOrCreate(['name' => 'Photography']);

        return Service::query()->create([
            'name' => $name,
            'description' => $name,
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => $category->id,
            'photo_count' => $photoCount,
            'uses_hdr_brackets' => $usesHdrBrackets,
            'upload_intake_type' => $intake,
            'pricing_type' => 'fixed',
        ]);
    }

    private function rawFileFor(ShootService $item, int $uploaderId): void
    {
        ShootFile::create([
            'shoot_id' => $item->shoot_id,
            'shoot_service_id' => $item->id,
            'filename' => 'raw-'.$item->id.'.jpg',
            'stored_filename' => 'raw-'.$item->id.'.jpg',
            'path' => 'shoots/'.$item->shoot_id.'/todo/raw.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'raw',
            'uploaded_by' => $uploaderId,
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);
    }

    public function test_all_three_columns_exist_after_migrating_forward(): void
    {
        $this->assertTrue(Schema::hasColumn('services', 'uses_hdr_brackets'));
        $this->assertTrue(Schema::hasColumn('users', 'default_bracket_mode'));
        $this->assertTrue(Schema::hasColumn('shoot_service', 'bracket_mode'));
    }

    public function test_uses_hdr_brackets_defaults_to_false_so_nothing_brackets_by_accident(): void
    {
        // Inserted without naming the column at all, as older code would.
        $category = Category::query()->firstOrCreate(['name' => 'Photography']);
        $id = DB::table('services')->insertGetId([
            'name' => 'Legacy Service',
            'description' => 'Legacy Service',
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => $category->id,
            'photo_count' => 30,
            'pricing_type' => 'fixed',
            'photographer_required' => 1,
            'allow_multiple' => 0,
            'exclude_from_sales_commission' => 0,
            'requires_editing' => 1,
            'photographer_pay_type' => 'fixed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse((bool) DB::table('services')->where('id', $id)->value('uses_hdr_brackets'));
    }

    public function test_default_bracket_mode_is_null_for_existing_photographers(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);

        // Never backfilled, so an existing photographer states no preference and
        // resolves to the product default.
        $this->assertNull($photographer->fresh()->default_bracket_mode);
    }

    public function test_the_pinning_backfill_freezes_only_bracketed_services_that_already_hold_raws(): void
    {
        $uploader = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => 3,
        ]);

        $bracketedWithRaws = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $this->service('Exterior HDR', 30, true)->id,
            'price' => 100, 'quantity' => 1,
        ]);
        $bracketedWithoutRaws = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $this->service('Twilight HDR', 12, true)->id,
            'price' => 100, 'quantity' => 1,
        ]);
        $nonBracketWithRaws = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $this->service('Aerial Drone Photos', 10, false)->id,
            'price' => 100, 'quantity' => 1,
        ]);

        $this->rawFileFor($bracketedWithRaws, $uploader->id);
        $this->rawFileFor($nonBracketWithRaws, $uploader->id);

        // Start from the pre-backfill state.
        DB::table('shoot_service')->update(['bracket_mode' => null]);

        // Re-run only the pinning migration.
        $this->runMigration('2026_08_23_000003_add_bracket_mode_to_shoot_service.php', 'up');

        // Frozen at the legacy shoot value, because these frames are already stacked
        // in threes and must not be re-cut.
        $this->assertSame(3, (int) $bracketedWithRaws->refresh()->bracket_mode);

        // No frames yet, so it stays open and can adopt the assigned photographer's
        // preference later instead of being frozen at a legacy value.
        $this->assertNull($bracketedWithoutRaws->refresh()->bracket_mode);

        // Never stamped with a size it has no use for, so NULL keeps meaning
        // "this work does not bracket".
        $this->assertNull($nonBracketWithRaws->refresh()->bracket_mode);
    }

    public function test_the_pinning_backfill_defaults_to_five_when_the_legacy_shoot_states_nothing(): void
    {
        $uploader = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => null,
        ]);

        $item = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $this->service('Exterior HDR', 30, true)->id,
            'price' => 100, 'quantity' => 1,
        ]);
        $this->rawFileFor($item, $uploader->id);

        DB::table('shoot_service')->update(['bracket_mode' => null]);

        $this->runMigration('2026_08_23_000003_add_bracket_mode_to_shoot_service.php', 'up');

        $this->assertSame(
            BracketModeResolver::DEFAULT_BRACKET_MODE,
            (int) $item->refresh()->bracket_mode
        );
    }

    public function test_rolling_back_and_forward_again_leaves_a_working_schema(): void
    {
        $paths = [
            '2026_08_23_000003_add_bracket_mode_to_shoot_service.php',
            '2026_08_23_000002_add_default_bracket_mode_to_users.php',
            '2026_08_23_000001_add_uses_hdr_brackets_to_services.php',
        ];

        foreach ($paths as $path) {
            $this->runMigration($path, 'down');
        }

        // Each down() drops only its own column, so all three are gone and nothing
        // else went with them.
        $this->assertFalse(Schema::hasColumn('shoot_service', 'bracket_mode'));
        $this->assertFalse(Schema::hasColumn('users', 'default_bracket_mode'));
        $this->assertFalse(Schema::hasColumn('services', 'uses_hdr_brackets'));
        $this->assertTrue(Schema::hasColumn('shoot_service', 'photographer_id'), 'rollback must not take neighbouring columns');
        $this->assertTrue(Schema::hasColumn('services', 'photo_count'));

        // With the columns absent, the resolver degrades instead of erroring: nothing
        // can be known to bracket, so nothing does.
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => 5,
        ]);
        $item = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => Service::query()->create([
                'name' => 'Exterior HDR',
                'description' => 'Exterior HDR',
                'price' => 100,
                'delivery_time' => 24,
                'category_id' => Category::query()->firstOrCreate(['name' => 'Photography'])->id,
                'photo_count' => 30,
                'upload_intake_type' => Service::INTAKE_PHOTO,
                'pricing_type' => 'fixed',
            ])->id,
            'price' => 100, 'quantity' => 1,
        ]);

        $resolver = app(BracketModeResolver::class);
        $this->assertNull($resolver->effectiveBracketMode($item));

        foreach (array_reverse($paths) as $path) {
            $this->runMigration($path, 'up');
        }

        $this->assertTrue(Schema::hasColumn('services', 'uses_hdr_brackets'));
        $this->assertTrue(Schema::hasColumn('users', 'default_bracket_mode'));
        $this->assertTrue(Schema::hasColumn('shoot_service', 'bracket_mode'));

        // And the same service now resolves normally once the catalogue says it
        // brackets.
        $item->service->update(['uses_hdr_brackets' => true]);
        $this->assertSame(5, $resolver->effectiveBracketMode($item->fresh()->load('service')));
    }

    /**
     * Load a migration and run it directly.
     *
     * `Artisan::call('migrate')` cannot be used here: these tests run inside
     * RefreshDatabase's transaction against an in-memory sqlite database, and the
     * migrator wants to VACUUM (illegal inside a transaction) and to re-create the
     * migrations table. Driving up()/down() on the migration object exercises the
     * same code without the surrounding bookkeeping. `require` re-evaluates on every
     * call — only the `_once` variants cache — so each call yields a fresh instance.
     */
    private function runMigration(string $file, string $direction): void
    {
        /** @var object $migration */
        $migration = require database_path('migrations/'.$file);
        $migration->{$direction}();

        // Both resolvers cache schema lookups for the life of the request, so they
        // have to be told the schema moved underneath them.
        BracketModeResolver::flushColumnCache();
        UploadIntakeResolver::flushColumnCache();
        Schema::getConnection()->getSchemaBuilder()->getColumnListing('shoot_service');
    }
}
