<?php

namespace Tests\Feature;

use App\Events\SmsMessageSent;
use App\Models\Message;
use App\Models\SmsGroup;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmsGroupAtomicRecordingTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'repro-sms-group-race-');
        config(['database.connections.sqlite.database' => $this->databaseFile,
            'database.connections.sqlite.foreign_key_constraints' => true, 'broadcasting.default' => 'log']);
        DB::purge('sqlite');
        DB::statement('PRAGMA journal_mode = WAL');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['database.connections.sms_group_observer' => config('database.connections.sqlite')]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sms_group_observer');
        DB::disconnect('sqlite');
        if (isset($this->databaseFile)) {
            foreach ([$this->databaseFile, $this->databaseFile.'-wal', $this->databaseFile.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }

    public function test_other_connections_never_observe_an_empty_group_thread_and_broadcasts_follow_commit(): void
    {
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $actor = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($actor);
        SmsNumber::create(['phone_number' => '+12025550100', 'provider' => 'TELNYX', 'is_default' => true]);
        $group = SmsGroup::create(['name' => 'Concurrent crew', 'created_by' => $actor->id]);
        $group->members()->create(['phone' => '+12025550112', 'name' => 'Photographer']);
        $this->mock(TelnyxSmsProvider::class)->shouldReceive('send')->once()->andReturn('fake-atomic-group');

        $peer = DB::connection('sms_group_observer');
        $emptyThreadObservations = [];
        Message::creating(function (Message $message) use ($group, $peer, &$emptyThreadObservations) {
            if ($message->to_address === 'group:'.$group->id) {
                // A concurrent request must not see an empty thread and create
                // another one before this first visible copy has been stored.
                $emptyThreadObservations[] = $peer->table('message_threads')->where('sms_group_id', $group->id)
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('messages')
                        ->whereColumn('messages.thread_id', 'message_threads.id'))->count();
            }
        });
        $broadcastSawCommittedMessage = false;
        Event::listen(SmsMessageSent::class, function (SmsMessageSent $event) use ($group, $peer, &$broadcastSawCommittedMessage) {
            if ($event->message->to_address === 'group:'.$group->id) {
                $broadcastSawCommittedMessage = $peer->table('messages')->where('id', $event->message->id)->exists();
            }
        });

        $this->postJson('/api/messaging/sms/send', ['group_ids' => [$group->id], 'body_text' => 'Crew message'])->assertOk();
        $this->assertSame([0], $emptyThreadObservations);
        $this->assertTrue($broadcastSawCommittedMessage);
        $this->assertSame(1, $peer->table('message_threads')->where('sms_group_id', $group->id)->count());
    }
}
