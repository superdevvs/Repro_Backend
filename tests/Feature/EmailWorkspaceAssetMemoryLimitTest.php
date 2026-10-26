<?php

namespace Tests\Feature;

use App\Jobs\ProcessEmailTemplateAsset;
use App\Models\User;
use App\Services\Messaging\EmailWorkspaceAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class EmailWorkspaceAssetMemoryLimitTest extends TestCase
{
    use RefreshDatabase;

    private function processAsset(): string
    {
        $id = (string) Str::uuid();
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('email_template_assets')->insert([
            'id' => $id, 'user_id' => $user->id, 'name' => 'Memory limit regression',
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $observed = null;
        $assets = \Mockery::mock(EmailWorkspaceAssets::class);
        $assets->shouldReceive('process')->once()->andReturnUsing(function ($row) use (&$observed) {
            $observed = ini_get('memory_limit');
            DB::table('email_template_assets')->where('id', $row->id)->update(['status' => 'ready']);
        });
        (new ProcessEmailTemplateAsset($id))->handle($assets);
        $this->assertDatabaseHas('email_template_assets', ['id' => $id, 'status' => 'ready']);
        return $observed;
    }

    public function test_higher_and_unlimited_memory_allowances_are_preserved(): void
    {
        $original = ini_get('memory_limit');
        try {
            foreach (['2G', '-1'] as $limit) {
                ini_set('memory_limit', $limit);
                $this->assertSame($limit, $this->processAsset());
                $this->assertSame($limit, ini_get('memory_limit'));
            }
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_lower_finite_allowance_is_temporarily_raised_and_restored(): void
    {
        // Isolation keeps this fixture valid when the full suite's allocator
        // already exceeds 512 MB; no large image/buffer allocation is needed.
        $original = ini_get('memory_limit');
        $lowerMb = (int) ceil(memory_get_usage(true) / 1024 / 1024) + 16;
        $this->assertLessThan(512, $lowerMb);
        $lower = $lowerMb.'M';
        try {
            ini_set('memory_limit', $lower);
            $this->assertSame('512M', $this->processAsset());
            $this->assertSame($lower, ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $original);
        }
    }
}
