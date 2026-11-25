<?php

namespace Tests\Feature;

use App\Http\Controllers\API\Messaging\TelnyxWebhookController;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\VoiceCall;
use App\Services\Messaging\SharedMmsService;
use App\Support\TelnyxMaintenanceSpool;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TelnyxSpoolReplayTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_UUID = '0b6f6a8e-6f0d-4c1e-9c43-0f6b8d1f2a11';

    private string $spoolPath;

    private string $secretKey;

    private CarbonImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $keys = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keys);
        $this->spoolPath = sys_get_temp_dir().'/telnyx-replay-test-'.bin2hex(random_bytes(6));
        config([
            'services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keys)),
            'services.telnyx.webhook_tolerance_seconds' => 300,
            'services.telnyx.maintenance_capture.enabled' => true,
            'services.telnyx.maintenance_capture.spool_path' => $this->spoolPath,
            'services.telnyx.maintenance_capture.replay_group' => '',
            'services.telnyx.maintenance_capture.keep_days' => 14,
        ]);
        $this->t0 = CarbonImmutable::parse('2026-11-25 15:00:00', 'UTC');
        Carbon::setTestNow($this->t0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->maintenanceMode()->deactivate();
        File::deleteDirectory($this->spoolPath);
        parent::tearDown();
    }

    public function test_a_text_captured_while_down_is_replayed_once_after_up_at_its_arrival_time(): void
    {
        $this->app->maintenanceMode()->activate(['retry' => 60]);
        $body = $this->inbound('evt_e2e', 'msg_e2e', 'Lockbox is on the side door');
        $this->call('POST', '/api/webhooks/telnyx/messaging', [], [], [], $this->signedServer($body, $this->t0), $body)
            ->assertOk()->assertExactJson(['status' => 'captured']);
        $this->assertSame(0, Message::count());

        $this->app->maintenanceMode()->deactivate();
        Carbon::setTestNow($this->t0->addMinutes(7));
        $previousUmask = umask(0022);
        try {
            $this->artisan('telnyx:replay-spool')
                ->expectsOutputToContain('replayed 1, duplicates 0, set aside 0, failed 0')
                ->assertSuccessful();
            $replayUmask = umask();
        } finally {
            umask($previousUmask);
        }

        $this->assertSame(0002, $replayUmask);
        $message = Message::where('provider_message_id', 'msg_e2e')->sole();
        $this->assertSame('INBOUND', $message->direction);
        $this->assertSame($this->t0->getTimestamp(), $message->sent_at->getTimestamp());
        $this->assertSame([], $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS));
        $this->assertCount(1, $this->spool()->entries(TelnyxMaintenanceSpool::REPLAYED));

        $this->artisan('telnyx:replay-spool')
            ->expectsOutputToContain('replayed 0, duplicates 0, set aside 0, failed 0')
            ->assertSuccessful();
        $this->assertSame(1, Message::where('provider_message_id', 'msg_e2e')->count());
    }

    public function test_texts_are_replayed_in_arrival_order_whatever_order_they_were_saved_in(): void
    {
        $this->save('messaging', $this->inbound('evt_third', 'msg_third', 'third'), $this->t0->addSeconds(40));
        $this->save('messaging', $this->inbound('evt_first', 'msg_first', 'first'), $this->t0);
        $this->save('messaging', $this->inbound('evt_second', 'msg_second', 'second'), $this->t0->addSeconds(20));

        $this->artisan('telnyx:replay-spool')->assertSuccessful();

        $this->assertSame(['msg_first', 'msg_second', 'msg_third'],
            Message::orderBy('id')->pluck('provider_message_id')->all());
        $this->assertSame('third', MessageThread::sole()->last_snippet);
    }

    public function test_each_telnyx_message_and_event_is_replayed_once(): void
    {
        $existing = $this->inbound('evt_live', 'msg_already_stored', 'arrived before the deploy');
        $this->postLive($existing);
        $this->save('messaging', $this->inbound('evt_dup_a', 'msg_dup', 'hello'), $this->t0);
        // Telnyx retried with a new event id for the same message ...
        $this->save('messaging', $this->inbound('evt_dup_b', 'msg_dup', 'hello'), $this->t0->addSeconds(5));
        // ... and resent the same event a second time.
        $this->save('messaging', $this->inbound('evt_dup_a', 'msg_dup', 'hello'), $this->t0->addSeconds(9));
        $this->save('messaging', $existing, $this->t0->addSeconds(12));

        $this->artisan('telnyx:replay-spool')
            ->expectsOutputToContain('replayed 1, duplicates 3, set aside 0, failed 0')
            ->assertSuccessful();

        $this->assertSame(1, Message::where('provider_message_id', 'msg_dup')->count());
        $this->assertSame(1, Message::where('provider_message_id', 'msg_already_stored')->count());
        $this->assertCount(4, $this->spool()->entries(TelnyxMaintenanceSpool::REPLAYED));
    }

    public function test_status_events_for_the_same_message_are_all_applied_in_order(): void
    {
        $message = $this->outbound('msg_status_flow');
        $this->save('status', $this->event('evt_sent', 'message.sent', ['id' => 'msg_status_flow']), $this->t0);
        $final = $this->event('evt_final', 'message.finalized',
            ['id' => 'msg_status_flow', 'to' => [['phone_number' => '+12025550172', 'status' => 'delivered']]]);
        $this->save('messaging', $final, $this->t0->addSeconds(30));
        // Telnyx retried the same event while the app was down.
        $this->save('messaging', $final, $this->t0->addSeconds(50));

        $this->artisan('telnyx:replay-spool')
            ->expectsOutputToContain('replayed 2, duplicates 1')
            ->assertSuccessful();

        $message->refresh();
        $this->assertSame('DELIVERED', $message->status);
        $this->assertSame($this->t0->getTimestamp(), $message->sent_at->getTimestamp());
        $this->assertSame($this->t0->addSeconds(30)->getTimestamp(), $message->delivered_at->getTimestamp());
    }

    public function test_shared_mms_entries_replay_against_the_message_in_their_original_path(): void
    {
        $body = $this->event('evt_shared', 'message.finalized', ['id' => 'grp_1']);
        $shared = Mockery::mock(SharedMmsService::class);
        $shared->shouldReceive('webhook')->once()
            ->with(json_decode($body, true), self::SHARED_UUID)
            ->andReturn(['status' => 'updated']);
        $this->app->instance(SharedMmsService::class, $shared);
        $this->save('shared-mms', $body, $this->t0, self::SHARED_UUID);

        $this->artisan('telnyx:replay-spool')->expectsOutputToContain('replayed 1')->assertSuccessful();
    }

    public function test_voice_and_vapi_events_are_never_replayed(): void
    {
        $this->spool()->save(TelnyxMaintenanceSpool::CALLS, 'voice', null,
            $this->event('evt_call', 'call.initiated', ['call_session_id' => 's-1', 'from' => '+12025550181', 'to' => '+18883426998']),
            ['telnyx-signature-ed25519' => 'unused', 'telnyx-timestamp' => '0'], $this->t0);
        $this->spool()->save(TelnyxMaintenanceSpool::CALLS, 'vapi', null, '{"message":{"type":"status-update","call":{"id":"v-1"}}}', [], $this->t0);

        $this->artisan('telnyx:replay-spool')
            ->expectsOutputToContain('call events kept for telnyx:missed-calls: 2')
            ->assertSuccessful();

        $this->assertSame(0, VoiceCall::count());
        $this->assertCount(2, $this->spool()->entries(TelnyxMaintenanceSpool::CALLS));
    }

    public function test_forged_or_damaged_entries_are_set_aside_and_reported(): void
    {
        $this->save('messaging', $this->inbound('evt_good', 'msg_good', 'fine'), $this->t0);
        $forged = $this->inbound('evt_forged', 'msg_forged', 'not from Telnyx');
        $this->spool()->save(TelnyxMaintenanceSpool::TEXTS, 'messaging', null, $forged,
            ['telnyx-signature-ed25519' => base64_encode(str_repeat('x', 64)), 'telnyx-timestamp' => (string) $this->t0->getTimestamp()],
            $this->t0->addSecond());
        file_put_contents($this->spoolPath.'/texts/20261125T150002000000Z-00000000000000aa.spool', 'not an encrypted entry');

        $this->artisan('telnyx:replay-spool')
            ->expectsOutputToContain('replayed 1, duplicates 0, set aside 2, failed 0')
            ->assertFailed();

        $this->assertTrue(Message::where('provider_message_id', 'msg_good')->exists());
        $this->assertFalse(Message::where('provider_message_id', 'msg_forged')->exists());
        $this->assertCount(2, glob($this->spoolPath.'/rejected/*.spool'));
        $this->assertSame([], glob($this->spoolPath.'/texts/*.spool'));
    }

    public function test_an_entry_that_fails_stays_in_the_spool_for_the_next_run(): void
    {
        $this->save('messaging', $this->inbound('evt_fails', 'msg_fails', 'first'), $this->t0);
        $this->save('messaging', $this->inbound('evt_works', 'msg_works', 'second'), $this->t0->addSecond());
        $real = $this->app->make(TelnyxWebhookController::class);
        $flaky = Mockery::mock(TelnyxWebhookController::class);
        $flaky->shouldReceive('replayVerified')->andReturnUsing(function (...$arguments) use ($real): JsonResponse {
            if (data_get($arguments[2], 'data.id') === 'evt_fails') {
                // Database errors can carry SQL bindings such as numbers and text.
                throw new RuntimeException('database is locked: insert values (+12025550177, first)');
            }

            return $real->replayVerified(...$arguments);
        });
        $this->app->instance(TelnyxWebhookController::class, $flaky);
        Log::spy();

        $this->artisan('telnyx:replay-spool')
            ->expectsOutputToContain('failed: '.RuntimeException::class)
            ->expectsOutputToContain('replayed 1, duplicates 0, set aside 0, failed 1')
            ->assertFailed();
        $this->assertCount(1, $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('log');
        Log::shouldHaveReceived('warning')->once()->with('Telnyx spool replay could not handle one entry.',
            Mockery::on(fn (array $context) => $context['error'] === RuntimeException::class
                && ! str_contains(json_encode($context), '+12025550177')));

        $this->app->forgetInstance(TelnyxWebhookController::class);
        $this->artisan('telnyx:replay-spool')->expectsOutputToContain('replayed 1, duplicates 0, set aside 0, failed 0')->assertSuccessful();
        $this->assertSame(['msg_works', 'msg_fails'], Message::orderBy('id')->pluck('provider_message_id')->all());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->save('messaging', $this->inbound('evt_dry', 'msg_dry', 'hello'), $this->t0);

        $this->artisan('telnyx:replay-spool', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 text(s) waiting')
            ->assertSuccessful();

        $this->assertSame(0, Message::count());
        $this->assertCount(1, $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS));
    }

    public function test_replay_refuses_to_run_under_another_group(): void
    {
        if (! function_exists('posix_getegid')) {
            $this->markTestSkipped('Needs POSIX group functions.');
        }
        config(['services.telnyx.maintenance_capture.replay_group' => 'no-such-group-for-replay']);
        $this->save('messaging', $this->inbound('evt_group', 'msg_group', 'hello'), $this->t0);

        $this->artisan('telnyx:replay-spool')
            ->expectsOutputToContain('no-such-group-for-replay')
            ->assertFailed();

        $this->assertSame(0, Message::count());
        $this->assertCount(1, $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS));
    }

    public function test_an_empty_or_missing_spool_is_a_quiet_no_op(): void
    {
        $this->artisan('telnyx:replay-spool')->expectsOutputToContain('Telnyx spool is empty.')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->spoolPath);
    }

    public function test_old_replayed_call_and_rejected_entries_are_pruned_but_waiting_texts_are_kept(): void
    {
        // Entries are kept for keep_days after they were saved or last moved.
        // Those are real file times, so the clock moves on from the real now.
        $old = $this->t0->subDays(15);
        $spool = $this->spool();
        $waiting = $this->save('messaging', $this->inbound('evt_old_waiting', 'msg_old_waiting', 'still waiting'), $old);
        foreach ([TelnyxMaintenanceSpool::REPLAYED, TelnyxMaintenanceSpool::REJECTED] as $folder) {
            $id = $spool->save(TelnyxMaintenanceSpool::TEXTS, 'messaging', null, '{}', [], $old);
            $spool->move($id, TelnyxMaintenanceSpool::TEXTS, $folder);
        }
        $spool->save(TelnyxMaintenanceSpool::CALLS, 'vapi', null, '{}', [], $old);
        $later = CarbonImmutable::createFromTimestamp(time())->addDays(15);
        Carbon::setTestNow($later);
        $recentCall = $spool->save(TelnyxMaintenanceSpool::CALLS, 'vapi', null, '{}', [], $later->subDays(2));
        touch($this->spoolPath.'/calls/'.$recentCall, $later->subDays(2)->getTimestamp());

        // The old signature on the waiting text is accepted: freshness is
        // checked against its arrival time, not against the replay time.
        $this->artisan('telnyx:replay-spool')->expectsOutputToContain('replayed 1')->assertSuccessful();

        $this->assertSame([$waiting], array_column($spool->entries(TelnyxMaintenanceSpool::REPLAYED), 'id'));
        $this->assertSame([], $spool->entries(TelnyxMaintenanceSpool::REJECTED));
        $this->assertSame([$recentCall], array_column($spool->entries(TelnyxMaintenanceSpool::CALLS), 'id'));
    }

    public function test_a_handled_entry_is_kept_even_when_its_file_time_could_not_be_restamped(): void
    {
        // On the server the deploy user moves files that PHP-FPM owns, so it
        // cannot restamp their mtime; the move itself still updates the ctime.
        $old = $this->t0->subDays(15);
        $id = $this->save('messaging', $this->inbound('evt_late', 'msg_late', 'waited a long time'), $old);
        $this->artisan('telnyx:replay-spool')->expectsOutputToContain('replayed 1')->assertSuccessful();
        // What a failed restamp leaves behind: the old mtime and a fresh ctime.
        touch($this->spoolPath.'/replayed/'.$id, $old->getTimestamp());

        $this->artisan('telnyx:replay-spool')->assertSuccessful();

        $this->assertSame([$id], array_column($this->spool()->entries(TelnyxMaintenanceSpool::REPLAYED), 'id'));
    }

    public function test_missed_call_list_shows_one_row_per_call_and_no_message_bodies(): void
    {
        $spool = $this->spool();
        $telnyx = ['telnyx-signature-ed25519' => 'unused', 'telnyx-timestamp' => '0'];
        $spool->save(TelnyxMaintenanceSpool::CALLS, 'voice', null, $this->event('evt_c1', 'call.initiated',
            ['call_session_id' => 'session-1', 'from' => '+12025550181', 'to' => '+18883426998']), $telnyx, $this->t0);
        $spool->save(TelnyxMaintenanceSpool::CALLS, 'voice', null, $this->event('evt_c2', 'call.hangup',
            ['call_session_id' => 'session-1', 'from' => '+12025550181', 'to' => '+18883426998']), $telnyx, $this->t0->addSeconds(30));
        $spool->save(TelnyxMaintenanceSpool::CALLS, 'vapi', null, json_encode(['message' => ['type' => 'status-update',
            'call' => ['id' => 'vapi-call-1', 'customer' => ['number' => '+12025550182']],
            'phoneNumber' => ['number' => '+18883426999'], 'transcript' => 'private words']]), [], $this->t0->addMinute());

        Artisan::call('telnyx:missed-calls');
        $output = Artisan::output();

        $this->assertStringContainsString('2 call(s)', $output);
        $this->assertSame(1, substr_count($output, '+12025550181'));
        $this->assertStringContainsString('call.initiated, call.hangup', $output);
        $this->assertStringContainsString('+12025550182', $output);
        $this->assertStringContainsString('2026-11-25 10:00', $output); // America/New_York
        $this->assertStringNotContainsString('private words', $output);
    }

    private function spool(): TelnyxMaintenanceSpool
    {
        return TelnyxMaintenanceSpool::fromConfig();
    }

    /** Saves a correctly signed text exactly as the capture middleware would. */
    private function save(string $route, string $body, CarbonImmutable $arrivedAt, ?string $routeKey = null): string
    {
        $timestamp = (string) $arrivedAt->getTimestamp();

        return $this->spool()->save(TelnyxMaintenanceSpool::TEXTS, $route, $routeKey, $body, [
            'telnyx-signature-ed25519' => $this->sign($timestamp, $body), 'telnyx-timestamp' => $timestamp,
        ], $arrivedAt);
    }

    private function inbound(string $eventId, string $messageId, string $text): string
    {
        return $this->event($eventId, 'message.received', [
            'id' => $messageId, 'from' => ['phone_number' => '+12025550177'],
            'to' => [['phone_number' => '+18883426998']], 'text' => $text,
        ]);
    }

    private function event(string $eventId, string $type, array $payload): string
    {
        return json_encode(['data' => ['id' => $eventId, 'event_type' => $type, 'payload' => $payload]]);
    }

    private function sign(string $timestamp, string $body): string
    {
        return base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, $this->secretKey));
    }

    private function signedServer(string $body, CarbonImmutable $at): array
    {
        $timestamp = (string) $at->getTimestamp();

        return ['CONTENT_TYPE' => 'application/json', 'HTTP_TELNYX_SIGNATURE_ED25519' => $this->sign($timestamp, $body),
            'HTTP_TELNYX_TIMESTAMP' => $timestamp];
    }

    private function postLive(string $body): void
    {
        // The live route checks freshness against the real clock.
        $this->call('POST', '/api/webhooks/telnyx/messaging', [], [], [], $this->signedServer($body, CarbonImmutable::createFromTimestamp(time())), $body)
            ->assertOk();
    }

    private function outbound(string $providerId): Message
    {
        $contact = Contact::create(['name' => 'Replay fixture', 'phone' => '+12025550172', 'type' => 'other']);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => 'SMS', 'last_message_at' => now()]);

        return Message::create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'provider' => 'TELNYX', 'provider_message_id' => $providerId,
            'from_address' => '+18883426998', 'to_address' => '+12025550172', 'body_text' => 'Replay fixture',
            'status' => 'QUEUED', 'thread_id' => $thread->id,
        ]);
    }
}
