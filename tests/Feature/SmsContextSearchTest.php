<?php

namespace Tests\Feature;

use App\Models\MessageThread;
use App\Services\Messaging\SmsInboxPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmsContextSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_context_matches_both_directions_and_normalized_exact_pairs_without_duplicates(): void
    {
        $legacy = $this->thread(false);
        $this->message($legacy, '+1 (202) 555-0100', '202-555-0112', 'OUTBOUND', 'Needle reminder');
        $this->message($legacy, '+12025550100', '+12025550112', 'OUTBOUND', 'Another Needle reminder');
        $incoming = $this->thread();
        $this->message($incoming, '(202) 555-0112', '+12025550100', 'INBOUND');
        $outgoing = $this->thread();
        $this->message($outgoing, '202.555.0100', '+1 (202) 555-0112', 'OUTBOUND');
        $wrongBusiness = $this->thread();
        $this->message($wrongBusiness, '+12025550112', '+12025550101', 'INBOUND');
        $wrongPerson = $this->thread();
        $this->message($wrongPerson, '+12025550113', '+12025550100', 'INBOUND');
        $hidden = $this->thread();
        $this->message($hidden, '+12025550112', '+12025550100', 'INBOUND', hidden: true);

        $this->assertSame([$incoming, $outgoing], $this->query('%needle%')->orderBy('id')->pluck('id')->all());
    }

    public function test_context_excludes_hidden_group_active_inbound_and_same_thread_reminders(): void
    {
        $thread = $this->thread();
        $this->message($thread, '+12025550112', '+12025550100', 'INBOUND');
        $hidden = $this->thread(false);
        $this->message($hidden, '+12025550100', '+12025550112', 'OUTBOUND', 'Needle hidden', true);
        $active = $this->thread();
        $this->message($active, '+12025550100', '+12025550112', 'OUTBOUND', 'Needle active');
        $inbound = $this->thread(false);
        $this->message($inbound, '+12025550100', '+12025550112', 'INBOUND', 'Needle inbound');
        $groupId = DB::table('sms_groups')->insertGetId(['name' => 'Private group', 'created_at' => now(), 'updated_at' => now()]);
        $group = $this->thread(false, $groupId);
        $this->message($group, '+12025550100', '+12025550112', 'OUTBOUND', 'Needle group');

        $this->assertSame([], $this->query('%needle%')->pluck('id')->all());

        $ownReminder = $this->thread(false);
        $this->message($ownReminder, '+12025550101', '+12025550113', 'OUTBOUND', 'Same thread only');
        $query = app(SmsInboxPolicy::class)->searchContext(MessageThread::query()->whereKey($ownReminder), '%Same thread only%');
        $this->assertFalse($query->exists());
    }

    public function test_context_body_search_is_an_uncorrelated_set_lookup(): void
    {
        $legacy = $this->thread(false);
        $this->message($legacy, '+12025550100', '+12025550112', 'OUTBOUND', 'Literal %_ needle');
        $thread = $this->thread();
        $this->message($thread, '+12025550112', '+12025550100', 'INBOUND');
        $query = $this->query('%\\%\\_%');

        $this->assertSame([$thread], $query->pluck('id')->all());
        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))->pluck('detail')->implode('\n');
        $this->assertStringNotContainsString('CORRELATED', strtoupper($plan));
        $this->assertStringContainsString('LIST SUBQUERY', strtoupper($plan));
    }

    private function query(string $pattern): \Illuminate\Database\Eloquent\Builder
    {
        return app(SmsInboxPolicy::class)->searchContext(MessageThread::query()->where('channel', 'SMS')->where('sms_inbox_active', true), $pattern);
    }

    private function thread(bool $active = true, ?int $group = null): int
    {
        $contact = DB::table('contacts')->insertGetId(['name' => 'Search fixture', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('message_threads')->insertGetId(['channel' => 'SMS', 'contact_id' => $contact, 'sms_inbox_active' => $active, 'sms_group_id' => $group,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function message(int $thread, string $from, string $to, string $direction, string $body = 'Unrelated visible conversation', bool $hidden = false): void
    {
        DB::table('messages')->insert(['thread_id' => $thread, 'channel' => 'SMS', 'direction' => $direction, 'provider' => 'TEST',
            'from_address' => $from, 'to_address' => $to, 'body_text' => $body, 'hidden_from_inbox' => $hidden, 'status' => 'DELIVERED',
            'created_at' => now(), 'updated_at' => now()]);
    }
}
