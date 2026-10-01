<?php

namespace Tests\Unit;

use App\Jobs\CloseVoiceOfferDevices;
use App\Jobs\DialVoiceStaffPhone;
use App\Jobs\ReconcileVoiceIncomingOffer;
use App\Jobs\RecoverVoiceTranscript;
use App\Support\LockedWrite;
use PDO;
use PDOException;
use Tests\TestCase;

class VoiceQueueAndClaimContractTest extends TestCase
{
    public function test_timed_call_jobs_use_dedicated_queues_and_keep_recovery_separate(): void
    {
        foreach ([new ReconcileVoiceIncomingOffer('offer'), new CloseVoiceOfferDevices('offer'), new DialVoiceStaffPhone('phone')] as $job) {
            $this->assertSame('database', $job->connection);
            $this->assertSame('voice-realtime', $job->queue);
            $this->assertTrue($job->afterCommit);
        }
        $recovery = new RecoverVoiceTranscript('recovery');
        $this->assertSame('voice-transcripts', $recovery->queue);
        $this->assertSame(1, $recovery->tries);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $recovery->timeout);
    }

    public function test_two_sqlite_wal_connections_cannot_win_the_same_offer_after_snapshot_retry(): void
    {
        // Exercise the same conditional update and short-transaction retry against
        // real independent SQLite WAL connections; HTTP/service behavior is covered
        // by VoiceIncomingOfferTest. No provider requests are involved here.
        $file = tempnam(sys_get_temp_dir(), 'voice-cas-');
        $first = $second = null;
        try {
            $first = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $second = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $first->exec('PRAGMA journal_mode=WAL');
            $first->exec('PRAGMA busy_timeout=20');
            $second->exec('PRAGMA busy_timeout=20');
            $first->exec('CREATE TABLE offers (id TEXT PRIMARY KEY, voice_call_id INTEGER UNIQUE, status TEXT, claimed_by_id INTEGER, expires_at TEXT)');
            $first->exec("INSERT INTO offers VALUES ('offer', 1, 'waiting', NULL, '2099-01-01')");
            $attempts = 0;
            $result = LockedWrite::run(function () use ($first, $second, &$attempts): int {
                $attempts++;
                $second->beginTransaction();
                try {
                    $second->query("SELECT status FROM offers WHERE id='offer'")->fetchColumn();
                    if ($attempts === 1) {
                        $this->assertSame(1, $first->exec("UPDATE offers SET status='claimed', claimed_by_id=10 WHERE id='offer' AND status='waiting' AND expires_at > '2026-01-01'"));
                    }
                    $updated = $second->exec("UPDATE offers SET status='claimed', claimed_by_id=20 WHERE id='offer' AND status='waiting' AND expires_at > '2026-01-01'");
                    $second->commit();

                    return $updated;
                } catch (\Throwable $e) {
                    $second->rollBack();
                    throw $e;
                }
            }, 'voice.incoming.claim.race');
            $this->assertSame(2, $attempts);
            $this->assertSame(0, $result);
            $this->assertSame(10, (int) $first->query("SELECT claimed_by_id FROM offers WHERE id='offer'")->fetchColumn());
            try {
                $second->exec("INSERT INTO offers VALUES ('duplicate', 1, 'waiting', NULL, '2099-01-01')");
                $this->fail('A second offer for the same canonical call must violate uniqueness.');
            } catch (PDOException $e) {
                $this->assertSame('23000', $e->getCode());
            }
        } finally {
            $first = $second = null;
            foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
