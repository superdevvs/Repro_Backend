<?php

namespace Tests\Feature;

use App\Models\{Shoot, ShootUploadAttempt, User};
use App\Services\Shoots\ShootUploadIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ShootUploadRetryClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_retryable_failure_without_results_reuses_identity_once_while_pending_stays_protected(): void
    {
        $actor = User::factory()->create(); $shoot = Shoot::factory()->create();
        $request = Request::create('/upload', 'POST', ['upload_type' => 'raw', 'idempotency_key' => 'retry-same-key']);
        $service = app(ShootUploadIdempotencyService::class);
        $claim = $service->claim($request, $shoot, $actor, []);
        $service->fail($claim['attempt'], ['errors' => [['error_type' => 'server_error', 'retryable' => true]]]);
        $retry = $service->claim($request, $shoot, $actor, []);
        $this->assertSame($claim['attempt']->id, $retry['attempt']->id);
        $this->assertNull($retry['replay']);
        $this->assertSame(1, ShootUploadAttempt::count());
        $this->travel(3)->hours();
        $pending = $service->claim($request, $shoot, $actor, []);
        $this->assertNull($pending['attempt']);
        $this->assertSame(409, $pending['replay']['status']);
        $this->assertSame('upload_in_progress', $pending['replay']['payload']['error_type']);
    }

    public function test_terminal_rejections_are_replayed_without_new_work(): void
    {
        $actor = User::factory()->create(); $shoot = Shoot::factory()->create();
        $request = Request::create('/upload', 'POST', ['idempotency_key' => 'invalid-file']);
        $service = app(ShootUploadIdempotencyService::class);
        $claim = $service->claim($request, $shoot, $actor, []);
        $service->fail($claim['attempt'], ['errors' => [['error_type' => 'invalid_file', 'retryable' => false]]], 422);
        $retry = $service->claim($request, $shoot, $actor, []);
        $this->assertNull($retry['attempt']);
        $this->assertSame(422, $retry['replay']['status']);
    }
}
