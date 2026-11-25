<?php

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sanctum saves the token's last_used_at on every authenticated request. On SQLite every save
 * takes the single write lock, so the app keeps that save to at most once per interval
 * (sanctum.last_used_write_interval, five minutes by default).
 */
class TokenLastUsedThrottleTest extends TestCase
{
    use RefreshDatabase;

    private int $tokenUpdates = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function ($query): void {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'update') && str_contains($sql, 'personal_access_tokens')) {
                $this->tokenUpdates++;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_requests_inside_the_interval_save_last_used_at_once(): void
    {
        [$plain, $tokenId] = $this->issueToken();
        $start = Carbon::parse('2026-11-25 10:00:00');

        $this->authenticatedRequestAt($plain, $start);
        $this->assertSame(1, $this->tokenUpdates, 'A token that was never used is saved on first use.');
        $this->assertSame($start->toDateTimeString(), $this->storedLastUsedAt($tokenId));

        $this->authenticatedRequestAt($plain, $start->copy()->addMinute());
        $this->assertSame(1, $this->tokenUpdates, 'A second request one minute later must not write.');
        $this->assertSame($start->toDateTimeString(), $this->storedLastUsedAt($tokenId));

        $this->authenticatedRequestAt($plain, $start->copy()->addMinutes(6));
        $this->assertSame(2, $this->tokenUpdates, 'A request six minutes later writes again.');
        $this->assertSame($start->copy()->addMinutes(6)->toDateTimeString(), $this->storedLastUsedAt($tokenId));
    }

    public function test_interval_of_zero_keeps_the_per_request_save(): void
    {
        config(['sanctum.last_used_write_interval' => 0]);
        [$plain] = $this->issueToken();
        $start = Carbon::parse('2026-11-25 10:00:00');

        $this->authenticatedRequestAt($plain, $start);
        $this->authenticatedRequestAt($plain, $start->copy()->addMinute());

        $this->assertSame(2, $this->tokenUpdates);
    }

    public function test_skipped_save_leaves_other_changes_untouched(): void
    {
        [, $tokenId] = $this->issueToken();
        $start = Carbon::parse('2026-11-25 10:00:00');
        Carbon::setTestNow($start);
        PersonalAccessToken::query()->whereKey($tokenId)->update(['last_used_at' => $start]);

        Carbon::setTestNow($start->copy()->addMinute());
        $token = PersonalAccessToken::findOrFail($tokenId);
        $token->forceFill(['name' => 'renamed', 'last_used_at' => now()])->save();

        $this->assertSame('renamed', PersonalAccessToken::findOrFail($tokenId)->name,
            'Only a save that changes nothing but last_used_at may be skipped.');
    }

    /**
     * @return array{0:string,1:int}
     */
    private function issueToken(): array
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $plain = $user->createToken('mobile')->plainTextToken;
        $this->tokenUpdates = 0;

        return [$plain, (int) explode('|', $plain, 2)[0]];
    }

    private function authenticatedRequestAt(string $plain, Carbon $at): void
    {
        Carbon::setTestNow($at);
        // Each request must authenticate afresh, as it does in production.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/user')
            ->assertOk();
    }

    private function storedLastUsedAt(int $tokenId): ?string
    {
        $value = DB::table('personal_access_tokens')->where('id', $tokenId)->value('last_used_at');

        return $value === null ? null : Carbon::parse($value)->toDateTimeString();
    }
}
