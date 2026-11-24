<?php

namespace Tests\MySql;

use App\Models\User;
use App\Services\Crm\CrmWebsiteIntakeReceipt;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class CrmWebsiteIntakeConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public function test_mysql_overlapping_inquiry_replays_complete_one_receipt_and_one_business_effect(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame(0, DB::transactionLevel());
        $key = 'base64:'.base64_encode(random_bytes(32));
        config(['app.key' => $key, 'crm.enabled' => true]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $reference = (string) Str::uuid();
        $admin = User::withoutEvents(fn () => User::factory()->superAdmin()->create());
        $inquiry = [
            'first_name' => 'Synthetic', 'last_name' => 'Inquiry '.$reference,
            'email' => 'crm-replay-'.$reference.'@example.test', 'phone' => '2025550123',
            'sms_consent' => true, 'marketing_email_consent' => true,
        ];
        $receipts = app(CrmWebsiteIntakeReceipt::class);
        $receipts->register('inquiry', strtoupper($reference), $inquiry);
        $row = DB::table('crm_website_intake_receipts')->where(['kind' => 'inquiry', 'reference' => $reference]);
        $submitted = (array) $row->sole();
        $this->assertNull($submitted['completed_at']);
        $this->assertNotNull($submitted['payload']);

        $directory = sys_get_temp_dir().'/repro-mysql-crm-inquiry-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $workers = [];

        try {
            file_put_contents($directory.'/reference', $reference);
            chmod($directory.'/reference', 0600);
            foreach (['a', 'b'] as $slot) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/crm-inquiry-replay-worker.php', $directory, $slot],
                    null, ['APP_KEY' => $key]);
                $worker->setTimeout(45);
                $workers[$slot] = $worker;
                $worker->start();
            }
            $this->awaitMarker($directory.'/ready-a');
            $this->awaitMarker($directory.'/ready-b');
            $connections = array_map(fn (string $slot) => (int) file_get_contents($directory.'/ready-'.$slot), ['a', 'b']);
            $this->assertGreaterThan(0, min($connections));
            $this->assertNotSame($connections[0], $connections[1]);

            file_put_contents($directory.'/start-a', '1');
            $this->awaitMarker($directory.'/selected-a');
            file_put_contents($directory.'/start-b', '1');
            // This session sees connection B actually issuing the locking query
            // while A still holds the row and before A can complete the receipt.
            $deadline = microtime(true) + 8;
            do {
                $waiting = collect(DB::select('SHOW FULL PROCESSLIST'))->contains(fn ($thread) => (int) $thread->Id === $connections[1] && is_string($thread->Info)
                    && str_contains($thread->Info, '`crm_website_intake_receipts`')
                    && str_contains(strtolower($thread->Info), 'for update'));
                if (! $waiting) {
                    usleep(20_000);
                }
            } while (! $waiting && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second MySQL session must issue its receipt lock while the first holds it.');
            $this->assertFileDoesNotExist($directory.'/selected-b');
            file_put_contents($directory.'/release-a', '1');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertSame('receipt-processed-without-provider-effects', trim($worker->getOutput()));
            }

            $completed = (array) $row->sole();
            $this->assertSame($submitted['id'], $completed['id']);
            $this->assertNotNull($completed['completed_at']);
            $this->assertNull($completed['payload']);
            $receipts->register('inquiry', $reference, $inquiry);
            $receipts->process('inquiry', $reference);
            $this->assertSame($completed, (array) $row->sole());

            $contact = DB::table('crm_contacts')->where('email_normalized', $inquiry['email'])->sole();
            $this->assertSame('inquiry', $contact->stage);
            $this->assertSame('website', $contact->lead_source);
            $this->assertNull($contact->assigned_rep_id);
            $this->assertSame('website', $contact->sms_consent_source);
            $this->assertSame('website', $contact->marketing_consent_source);
            $activity = DB::table('crm_activities')->where('contact_id', $contact->id);
            $this->assertSame(1, (clone $activity)->where('type', 'website_inquiry')->where('data->reference', $reference)->count());
            $this->assertSame(1, (clone $activity)->where('type', 'sms_consent')->count());
            $this->assertSame(1, (clone $activity)->where('type', 'marketing_consent')->count());
            $this->assertSame(1, DB::table('crm_notices')->where([
                'contact_id' => $contact->id, 'user_id' => $admin->id, 'type' => 'website_inquiry',
            ])->count());
            Http::assertNothingSent();
            Mail::assertNothingSent();
            Notification::assertNothingSent();
            Queue::assertNothingPushed();
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function awaitMarker(string $file): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($file) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($file);
    }
}
