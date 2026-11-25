<?php

namespace Tests\MySqlWriters;

use App\Http\Controllers\API\AryeoWorkerController;
use App\Models\AryeoConnection;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MessageChannel;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\MySqlWriters\Concerns\WriterFixture;
use Tests\TestCase;

/**
 * WP-7: the plan's business paths as real separate processes with the writer
 * token on. Each worker sleeps briefly right after the read its path decides on
 * (see business-worker.php), so racers really overlap. Run with
 * MYSQL_WRITER_PROOF_TOKEN=off to start the workers without the token; the lane
 * notes record which proofs then fail and which other guard keeps the rest.
 */
final class WriterBusinessRacesTest extends TestCase
{
    use WriterFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWriterFixture();
        // This process only prepares rows and reads results; no side effect leaves it.
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownWriterFixture();
        parent::tearDown();
    }

    public function test_eight_editing_dispatches_for_one_shoot_give_one_job(): void
    {
        $this->admin->exec('DELETE FROM jobs');
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $editor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $shoot = Shoot::factory()->create(['status' => 'uploaded', 'workflow_status' => 'uploaded', 'editor_id' => null]);
        $service = Service::factory()->create(['upload_intake_type' => 'photo', 'category_id' => Category::firstOrCreate(['name' => 'Photos'])->id]);
        $shoot->services()->attach($service, ['price' => 100, 'quantity' => 1, 'editor_id' => $editor->id]);
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $shoot->services()->first()->pivot->id,
            'filename' => 'kitchen.jpg', 'stored_filename' => 'kitchen.jpg', 'path' => 'shoots/writer-policy/kitchen.jpg',
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'media_type' => 'photo',
            'workflow_stage' => 'todo', 'scan_status' => 'clean', 'uploaded_by' => $manager->id]);
        // One dialog's request, sent eight times at once (double clicks and client retries).
        $requestId = (string) Str::uuid();
        $payload = json_encode(['mode' => 'ai', 'scope' => 'selected', 'preset' => 'listing-ready', 'file_ids' => [$file->id],
            'source_versions' => [$file->id => 1], 'request_id' => $requestId], JSON_THROW_ON_ERROR);

        $results = $this->race(8, fn (int $slot) => $this->businessWorker('editing-dispatch',
            [(string) $slot, (string) $shoot->id, (string) $manager->id, $payload], ['QUEUE_CONNECTION' => 'database']));

        fwrite(STDERR, "\nEight sends of one editing request: ".json_encode(array_count_values(array_map(
            fn (array $result) => trim($result['status'].' '.$result['message']), $results)))."\n");
        $this->assertSame(array_fill(0, 8, 202), array_column($results, 'status'), json_encode($results));
        $dispatches = $this->admin->query('SELECT id FROM shoot_editing_dispatches WHERE request_id = '.$this->admin->quote($requestId))
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $dispatches);
        $this->assertSame(array_fill(0, 8, (string) $dispatches[0]), array_map('strval', array_column($results, 'dispatch')));
        $jobs = $this->admin->query("SELECT payload FROM jobs WHERE payload LIKE '%PrepareEditingDispatch%'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $jobs, 'Eight sends of one request must queue one editing job.');
        $this->assertStringContainsString((string) $dispatches[0], $jobs[0]);
        $this->assertFalse($this->tokenIsHeld());
    }

    public static function bookingCases(): array
    {
        return [
            // CreateShootAction's own transaction and day lock; the day has no booking to lock.
            'guard off, empty day' => ['off', 'scheduling', false, '2027-01-12'],
            // The day lock finds the photographer's 2 pm booking and locks it.
            'guard off, booked day' => ['off', 'scheduling', true, '2027-01-13'],
            // ScheduleCommitGuard: per-photographer lock, then a recheck in the transaction.
            'guard on, empty day' => ['on', 'scheduling', false, '2027-01-14'],
            // The same guard with its photographer lock made process-local: only the recheck is left.
            'guard on, empty day, no photographer lock' => ['on', 'array', false, '2027-01-15'],
        ];
    }

    #[DataProvider('bookingCases')]
    public function test_eight_bookings_for_one_slot_give_one_success(string $guard, string $lockStore, bool $bookedDay, string $date): void
    {
        $admin = User::factory()->admin()->create(['email_verification_required_at' => null]);
        $client = User::factory()->create(['role' => 'client', 'email_verification_required_at' => null]);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $service = Service::factory()->create(['name' => '10 Exterior HDR Photos', 'price' => 100, 'pricing_type' => 'fixed',
            'photographer_required' => true, 'shoot_duration_minutes' => 15]);
        $photographer->setServiceCapabilities([(string) $service->id]);
        $photographer->saveQuietly();
        $existing = null;
        if ($bookedDay) {
            $at = Carbon::parse($date.' 14:00', 'America/New_York')->utc();
            $existing = Shoot::factory()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id,
                'scheduled_at' => $at, 'scheduled_date' => $date, 'time' => '14:00', 'timezone' => 'America/New_York',
                'address' => '615 North Highland Avenue', 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
            $existing->services()->attach($service->id, ['duration_minutes' => 15, 'price' => 100, 'quantity' => 1,
                'photographer_id' => $photographer->id, 'scheduled_at' => $at, 'workflow_status' => 'scheduled']);
        }
        $payload = json_encode(['client_id' => $client->id, 'photographer_id' => $photographer->id,
            'address' => '5806 Winner Avenue', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21205',
            'scheduled_at' => $date.'T10:00:00-05:00', 'timezone' => 'America/New_York',
            'services' => [['id' => $service->id, 'quantity' => 1]]], JSON_THROW_ON_ERROR);

        $results = $this->race(8, fn (int $slot) => $this->businessWorker('book',
            [(string) $slot, (string) $admin->id, $payload, $guard, $lockStore], ['QUEUE_CONNECTION' => 'database']));

        $statuses = array_column($results, 'status');
        fwrite(STDERR, "\nEight bookings, guard $guard, lock store $lockStore".($bookedDay ? ', booked day' : '').': '
            .json_encode(array_count_values($statuses))."\n");
        $this->assertSame(1, count(array_keys($statuses, 201, true)), 'One booking may take the slot: '.json_encode($results));
        foreach ($statuses as $status) {
            $this->assertTrue($status === 201 || ($status >= 400 && $status < 500), 'A losing booking is refused, never a server error: '.json_encode($results));
        }
        $booked = (int) $this->admin->query('SELECT COUNT(*) FROM shoots WHERE photographer_id = '.(int) $photographer->id
            .($existing ? ' AND id <> '.(int) $existing->id : ''))->fetchColumn();
        $this->assertSame(1, $booked);
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_two_invoice_runs_per_role_give_one_set_of_invoices_and_unique_numbers(): void
    {
        [$start, $end] = ['2026-10-04', '2026-10-10'];
        $photographers = User::factory()->count(2)->photographer()->create();
        foreach ($photographers as $photographer) {
            $service = Service::factory()->create(['name' => 'HDR capture', 'price' => 175, 'photographer_pay' => 78.75, 'pricing_type' => 'fixed']);
            $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id, 'scheduled_date' => '2026-10-08',
                'shoot_type' => 'standard', 'status' => 'uploaded', 'workflow_status' => 'uploaded',
                'photos_uploaded_at' => '2026-10-08 18:00:00', 'completed_at' => null, 'admin_verified_at' => null]);
            $shoot->services()->attach($service->id, ['price' => 175, 'quantity' => 1, 'photographer_id' => $photographer->id]);
        }
        $rep = User::factory()->create(['role' => 'salesRep', 'metadata' => ['repDetails' => ['commissionPercentage' => 10]]]);
        $sold = Shoot::factory()->create(['rep_id' => $rep->id, 'scheduled_date' => '2026-10-06', 'total_quote' => 500, 'base_quote' => 500]);
        $sold->services()->attach(Service::factory()->create(['price' => 500, 'exclude_from_sales_commission' => false])->id,
            ['price' => 500, 'quantity' => 1]);
        $clients = User::factory()->count(2)->create(['role' => 'client']);
        foreach ($clients as $client) {
            $shoot = Shoot::factory()->create(['client_id' => $client->id, 'scheduled_date' => '2026-10-07', 'total_quote' => 200, 'base_quote' => 200]);
            $shoot->services()->attach(Service::factory()->create(['price' => 200])->id, ['price' => 200, 'quantity' => 1]);
        }
        // Two runs for each role, all at once: photographer payouts, sales-rep payouts, and each client's invoice.
        $kinds = ['photographer', 'photographer', 'sales_rep', 'sales_rep',
            'client:'.$clients[0]->id, 'client:'.$clients[0]->id, 'client:'.$clients[1]->id, 'client:'.$clients[1]->id];

        $this->race(8, fn (int $slot) => $this->businessWorker('payout', [(string) $slot, $kinds[$slot - 1], $start, $end]));

        $column = fn (string $sql) => $this->admin->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        $clientNumbers = array_map(fn (User $client) => $column("SELECT invoice_number FROM invoices WHERE role = 'client'
            AND shoot_id IS NULL AND user_id = ".(int) $client->id." AND period_start = '$start' ORDER BY id"), $clients->all());
        $numbers = array_merge(...$clientNumbers);
        $reused = $numbers === [] ? [] : $column('SELECT invoice_number FROM invoices WHERE invoice_number IN ('
            .implode(', ', array_map(fn ($number) => $this->admin->quote((string) $number), $numbers))
            .') GROUP BY invoice_number HAVING COUNT(*) > 1');
        $this->assertSame([
            'payout invoices per photographer' => [1, 1],
            'payout invoices for the sales rep' => 1,
            'period invoices per client' => [1, 1],
            'invoice numbers used twice' => [],
        ], [
            'payout invoices per photographer' => array_map(fn (User $photographer) => count($column("SELECT id FROM invoices
                WHERE role = 'photographer' AND photographer_id = ".(int) $photographer->id." AND billing_period_start = '$start'")),
                $photographers->all()),
            'payout invoices for the sales rep' => count($column("SELECT id FROM invoices WHERE role = 'salesRep'
                AND sales_rep_id = ".(int) $rep->id." AND billing_period_start = '$start'")),
            'period invoices per client' => array_map('count', $clientNumbers),
            'invoice numbers used twice' => $reused,
        ]);
        $this->assertNotContains(null, $numbers);
        $this->assertSame([], $column('SELECT work_key FROM payout_work_allocations GROUP BY role, recipient_id, work_key HAVING COUNT(*) > 1'));
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_one_payment_event_sent_five_times_gives_one_payment(): void
    {
        $shoot = Shoot::factory()->create(['total_quote' => 100, 'payment_status' => 'unpaid']);
        [$payload, $sessionId] = $this->checkoutCompleted($shoot);

        $results = $this->race(5, fn (int $slot) => $this->businessWorker('stripe-event', [(string) $slot, $payload, 'none'],
            ['QUEUE_CONNECTION' => 'database']));

        $outcomes = array_map(fn (array $result) => $result['status'].' '.$result['outcome'], $results);
        fwrite(STDERR, "\nFive deliveries of one payment event: ".json_encode(array_count_values(array_map(
            fn (array $result) => trim($result['status'].' '.$result['outcome'].' '.$result['error']), $results)))."\n");
        $this->assertSame(1, count(array_keys($outcomes, '200 processed', true)), json_encode($outcomes));
        foreach ($outcomes as $outcome) {
            // A delivery that lost the race is acknowledged or asked to retry; Stripe retries it later.
            $this->assertContains($outcome, ['200 processed', '200 already_processed', '500 failed', '500 busy']);
        }
        $this->assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM payments WHERE stripe_session_id = '
            .$this->admin->quote($sessionId))->fetchColumn());
        $this->assertEquals(100.0, (float) $this->admin->query('SELECT SUM(amount) FROM payments WHERE shoot_id = '.(int) $shoot->id)->fetchColumn());
        $this->assertSame('paid', $this->admin->query('SELECT payment_status FROM shoots WHERE id = '.(int) $shoot->id)->fetchColumn());
        // Stripe's later retry of the same event is acknowledged without a second payment.
        $retry = $this->worker('stripe-event', ['retry', $payload, 'none'], 240, ['QUEUE_CONNECTION' => 'database'], 'business-worker.php');
        $this->mark('go');
        $this->assertSame(['status' => 200, 'outcome' => 'already_processed', 'error' => null], $this->workerResult($retry)['result']);
        $this->assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM payments WHERE shoot_id = '.(int) $shoot->id)->fetchColumn());
        $this->assertFalse($this->tokenIsHeld());
    }

    /**
     * A writer holds a change open while a later writer commits. A reader that
     * pages by record number in between must not move its cursor past the open one.
     */
    public function test_the_aryeo_change_feed_never_skips_a_row_behind_a_delayed_writer(): void
    {
        [$seen, $client] = $this->readFeedAround('delayed');

        $committed = $this->feedIds($client);
        $this->assertSame($committed, $seen, 'The change feed skipped a committed row.');
    }

    /** A rolled-back writer leaves a gap in the record numbers; the feed moves past it and loses nothing. */
    public function test_the_aryeo_change_feed_moves_past_a_rolled_back_writer(): void
    {
        [$seen, $client, $shoots] = $this->readFeedAround('rollback');

        $this->assertSame($this->feedIds($client), $seen);
        $this->assertSame(0, (int) $this->admin->query("SELECT COUNT(*) FROM shoots WHERE notes = 'rollback writer'")->fetchColumn());
        $this->assertSame('second writer', $this->admin->query('SELECT notes FROM shoots WHERE id = '.(int) $shoots[1]->id)->fetchColumn());
    }

    /** @return array{0: list<int>, 1: User, 2: list<Shoot>} */
    private function readFeedAround(string $role): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoots = [Shoot::factory()->create(['client_id' => $client->id]), Shoot::factory()->create(['client_id' => $client->id])];
        $connection = (new AryeoConnection)->forceFill(['client_ids' => [$client->id]]);
        [$seen, $cursor] = $this->readFeed($connection, 0);

        $first = $this->businessWorker('aryeo-write', [$role, (string) $shoots[0]->id]);
        $second = $this->businessWorker('aryeo-write', ['second', (string) $shoots[1]->id]);
        $this->awaitMarker('a-wrote');
        // Give the second writer time to commit if nothing makes it wait.
        $deadline = microtime(true) + 1.5;
        while (! is_file($this->markers.'/b-done') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        [$page, $cursor] = $this->readFeed($connection, $cursor);
        $this->mark('a-release');
        $this->assertSame($role === 'rollback' ? 'rolled-back' : 'committed', $this->workerResult($first)['result']);
        $this->assertSame('committed', $this->workerResult($second)['result']);
        [$rest] = $this->readFeed($connection, $cursor);
        $all = array_values(array_unique(array_merge($seen, $page, $rest)));
        sort($all);
        $this->assertFalse($this->tokenIsHeld());

        return [$all, $client, $shoots];
    }

    /** Page through AryeoWorkerController::changes from a cursor until it returns nothing new. */
    private function readFeed(AryeoConnection $connection, int $cursor): array
    {
        $ids = [];
        do {
            $request = Request::create('/api/aryeo/worker/changes', 'GET', ['cursor' => $cursor]);
            $request->attributes->set('aryeo_connection', $connection);
            $page = app(AryeoWorkerController::class)->changes($request)->getData(true);
            $ids = array_merge($ids, array_map(fn (array $row) => (int) $row['id'], $page['data']));
            $moved = (int) $page['next_cursor'] !== $cursor;
            $cursor = (int) $page['next_cursor'];
        } while ($moved && $page['data'] !== []);

        return [$ids, $cursor];
    }

    private function feedIds(User $client): array
    {
        return array_map('intval', $this->admin->query('SELECT id FROM aryeo_changes WHERE client_id = '.(int) $client->id
            .' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** A signed checkout.session.completed for a dashboard checkout of the whole shoot, as Stripe sends it. */
    private function checkoutCompleted(Shoot $shoot): array
    {
        $suffix = bin2hex(random_bytes(6));
        $session = ['id' => 'cs_writer_policy_'.$suffix, 'object' => 'checkout.session', 'mode' => 'payment',
            'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_writer_policy_'.$suffix,
            'amount_total' => 10000, 'currency' => 'usd',
            'success_url' => 'https://reprodashboard.com/payment/success', 'cancel_url' => 'https://reprodashboard.com/payment/cancel',
            'metadata' => ['type' => 'single', 'shoot_id' => (string) $shoot->id, 'environment' => 'testing']];

        return [json_encode(['id' => 'evt_writer_policy_'.$suffix, 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => $session]], JSON_THROW_ON_ERROR), $session['id']];
    }

    private function businessWorker(string $mode, array $arguments, array $environment = []): Process
    {
        return $this->worker($mode, $arguments, 240, $environment, 'business-worker.php');
    }

    /**
     * Start $count workers, wait until every one has booted and is ready, then
     * release them together. Returns each worker's result in slot order.
     *
     * @param  \Closure(int): Process  $start
     */
    private function race(int $count, \Closure $start): array
    {
        $workers = [];
        foreach (range(1, $count) as $slot) {
            $workers[$slot] = $start($slot);
        }
        foreach (range(1, $count) as $slot) {
            $this->awaitMarker('ready-'.$slot, 120);
        }
        $this->mark('go');

        return array_values(array_map(fn (Process $worker) => $this->workerResult($worker)['result'], $workers));
    }
}
