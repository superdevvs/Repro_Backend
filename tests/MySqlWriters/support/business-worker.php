<?php

// A separate process for the WP-7 business proofs. It boots the application and
// runs one real business path through its own 'mysql' connection, with the
// writer policy as configured. Provider calls are faked; nothing leaves the host.

if (getenv('MYSQL_WRITER_POLICY_FIXTURE') !== 'isolated-v1' || getenv('DB_DATABASE') !== 'repro_mysql_test'
    || getenv('DB_USERNAME') !== 'repro_test' || getenv('DB_SOCKET') !== '/fixture-socket/mysql.sock') {
    throw new RuntimeException('This worker only runs in the isolated MySQL writer fixture.');
}
[$mode, $directory] = array_slice($argv, 1, 2);
$arguments = array_slice($argv, 3);
if (! is_string($directory) || ! str_starts_with($directory, sys_get_temp_dir().'/repro-writer-policy-') || ! is_dir($directory)) {
    throw new RuntimeException('Invalid marker directory.');
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\LockedWrite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();
config(['broadcasting.default' => 'null', 'app.timezone' => 'UTC', 'mail.default' => 'array']);

$mark = static fn (string $name) => file_put_contents($directory.'/'.$name, '1');
$await = static function (string $name, float $seconds) use ($directory): void {
    $deadline = microtime(true) + $seconds;
    while (! is_file($directory.'/'.$name) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($directory.'/'.$name)) {
        throw new RuntimeException("Timed out waiting for marker $name.");
    }
};

$db = DB::connection('mysql');
$tokenExpected = getenv('MYSQL_WRITER_PROOF_TOKEN') !== 'off';
if (! $db instanceof App\Database\MySqlConnection || $db->getConfig('repro_writer_policy') !== $tokenExpected) {
    throw new RuntimeException('The worker must use the application connection with the writer policy as the proof requires.');
}

/**
 * Widen a race window the way a slow disk or a busy host would: sleep right
 * after the first query that matches, inside whatever transaction is open. With
 * the token the other writers wait their turn; without it they run in the gap.
 */
$pauseAfter = static function (string $pattern, float $seconds, int $minimumLevel = 0) use ($db): void {
    $left = 1;
    DB::listen(function ($query) use ($pattern, $seconds, $minimumLevel, $db, &$left) {
        if ($left > 0 && $db->transactionLevel() >= $minimumLevel && preg_match($pattern, $query->sql) === 1) {
            $left--;
            usleep((int) ($seconds * 1_000_000));
        }
    });
};

// A caller that gets "busy" retries, as the app tells it to. Only a token wait,
// which happens before anything is written, counts as busy.
$busy = 0;
$retryBusy = static function (callable $call, int $attempts = 8) use (&$busy) {
    for ($attempt = 1; ; $attempt++) {
        try {
            return $call();
        } catch (Throwable $error) {
            if ($attempt >= $attempts || ! LockedWrite::isLockContention($error)) {
                throw $error;
            }
            $busy++;
            usleep(200_000);
        }
    }
};

// The class and driver code of the last error the application logged, for the evidence.
$reported = null;
Illuminate\Support\Facades\Event::listen(Illuminate\Log\Events\MessageLogged::class, function ($logged) use (&$reported) {
    $error = $logged->context['exception'] ?? null;
    if (! in_array($logged->level, ['error', 'critical', 'alert', 'emergency'], true)) {
        return;
    }
    if (is_string($error)) {
        // ApiErrorResponder logs the class name and the driver code, never the message.
        $reported = $error.(isset($logged->context['database_driver_code']) ? ' '.$logged->context['database_driver_code'] : '');

        return;
    }
    if (! $error instanceof Throwable) {
        return;
    }
    $code = null;
    for ($current = $error; $current !== null && $code === null; $current = $current->getPrevious()) {
        $code = $current instanceof PDOException || $current instanceof Illuminate\Database\QueryException ? ($current->errorInfo[1] ?? null) : null;
    }
    $reported = $error::class.($code !== null ? ' '.$code : '');
});

// One request through the HTTP kernel: routing, middleware, validation and controller.
$http = static function (string $method, string $uri, array $data = [], ?App\Models\User $user = null,
    array $server = [], ?string $content = null) use (&$reported): array {
    $reported = null;
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::create($uri, $method, server: ['CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json'] + $server, content: $content ?? json_encode($data, JSON_THROW_ON_ERROR));
    if ($user !== null) {
        Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
    }
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $body = json_decode((string) $response->getContent(), true);

    $message = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? $body['outcome'] ?? '') : '';

    return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : null,
        'message' => $reported !== null ? trim($message.' ['.$reported.']') : $message];
};

$signedStripeEvent = static function (string $payload): array {
    $time = time();

    return ['HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload,
        (string) config('services.stripe.webhook_secret'))];
};

$stripeConfig = static fn (?string $secretKey) => config([
    'services.stripe.webhook_secret' => 'whsec_writer_policy_proof', 'services.stripe.secret_key' => $secretKey,
    'services.stripe.app_instance' => 'reprodashboard-testing', 'app.url' => 'https://api.reprodashboard.com',
    'app.frontend_url' => 'https://reprodashboard.com', 'listing-studio.stripe_enabled' => false,
]);

$started = microtime(true);
$result = match ($mode) {
    // One "send to editing" request for a shoot, as a double click or a client retry would send it.
    'editing-dispatch' => (function () use ($mark, $await, $arguments, $http, $pauseAfter) {
        [$slot, $shootId, $userId, $payload] = $arguments;
        config(['media.local_disk' => 'local', 'media.tiered_storage_enabled' => false, 'media.dual_write' => false,
            'media.r2_only' => false, 'media.read_from_r2' => false, 'media.archive_prewarm' => false]);
        app()->instance(App\Services\Studio\StudioProviderSettings::class, new class extends App\Services\Studio\StudioProviderSettings
        {
            public function __construct() {}

            public function route(string $service, ?array $stored = null): array
            {
                return ['provider' => 'autoenhance', 'model' => 'writer-policy-proof'];
            }

            public function readiness(string $service, array $route, ?array $stored = null): array
            {
                return ['ready' => true];
            }
        });
        $user = App\Models\User::findOrFail((int) $userId);
        $pauseAfter('/from `shoot_editing_dispatches` where `request_id` = \?/', 0.3);
        $mark('ready-'.$slot);
        $await('go', 150);
        $response = $http('POST', '/api/shoots/'.$shootId.'/editing-dispatch', json_decode($payload, true), $user);

        return ['status' => $response['status'], 'dispatch' => $response['body']['data']['dispatchId'] ?? null,
            'message' => $response['message']];
    })(),
    // One staff booking for a slot through POST /api/shoots, with the schedule guard on or off.
    'book' => (function () use ($mark, $await, $arguments, $http, $pauseAfter) {
        [$slot, $userId, $payload, $guard, $lockStore] = $arguments;
        config(['availability.hybrid_travel_enabled' => $guard === 'on', 'availability.scheduling_lock_store' => $lockStore,
            'availability.buffer_time_minutes' => 15, 'availability.fallback_start_time' => '00:00',
            'availability.fallback_end_time' => '23:59', 'availability.radius_enforcement' => false]);
        // Travel is evaluated offline: fixed verified locations and a ten-minute drive.
        $location = static fn (?string $address) => ['verified' => true, 'complete' => true, 'precision' => 'exact',
            'building_key' => (string) $address, 'address_hash' => hash('sha256', (string) $address),
            'full_address' => $address.', Baltimore, MD', 'latitude' => 39.2, 'longitude' => -76.6];
        $locations = Mockery::mock(App\Services\Scheduling\TravelLocationResolver::class);
        $locations->shouldReceive('reset')->andReturnNull();
        $locations->shouldReceive('forPayload')->andReturnUsing(fn ($payload) => $location($payload['address'] ?? null));
        $locations->shouldReceive('forShoot')->andReturnUsing(fn ($shoot) => $location($shoot->address));
        $locations->shouldReceive('persistedMetadata')->andReturnUsing(fn ($found) => ['building_key' => $found['building_key'],
            'signature' => 'writer-policy-proof']);
        app()->instance(App\Services\Scheduling\TravelLocationResolver::class, $locations);
        $routes = Mockery::mock(App\Services\Scheduling\GoogleRoutesProvider::class);
        $routes->shouldReceive('reset')->andReturnNull();
        $routes->shouldReceive('route')->andReturn(['status' => 'ok', 'duration_seconds' => 600, 'distance_meters' => 1609,
            'reason_code' => 'traffic_aware_route']);
        app()->instance(App\Services\Scheduling\GoogleRoutesProvider::class, $routes);
        $user = App\Models\User::findOrFail((int) $userId);
        // The day lock is the booking's check: a locking read of the photographer's day.
        $pauseAfter('/^select .* from `shoots` where `photographer_id` = \?.* for update$/s', 0.3, 1);
        $mark('ready-'.$slot);
        $await('go', 150);
        $response = $http('POST', '/api/shoots', json_decode($payload, true), $user);

        return ['status' => $response['status'], 'shoot' => $response['body']['data']['id'] ?? null, 'message' => $response['message'],
            'reasons' => $response['body']['feasibility']['reason_codes'] ?? null];
    })(),
    // One weekly invoice run for a role: photographer payouts, sales-rep payouts, or one client's invoice.
    'payout' => (function () use ($mark, $await, $arguments, $retryBusy, $pauseAfter) {
        [$slot, $kind, $start, $end] = $arguments;
        $service = app(App\Services\InvoiceService::class);
        [$start, $end] = [Carbon\Carbon::parse($start, 'UTC'), Carbon\Carbon::parse($end, 'UTC')];
        // Invoice numbers are the last number plus one; pause after reading it.
        $pauseAfter('/where `invoice_number` is not null order by `id` desc limit 1/', 0.3, 1);
        $mark('ready-'.$slot);
        $await('go', 150);
        $invoices = $retryBusy(fn () => match (true) {
            $kind === 'photographer' => $service->generateForPeriod($start, $end, false),
            $kind === 'sales_rep' => $service->generateSalesRepInvoicesForPeriod($start, $end, false),
            str_starts_with($kind, 'client:') => collect([$service->generateInvoice(
                App\Models\User::findOrFail((int) substr($kind, 7)), 'client', $start, $end)]),
        });

        return ['invoices' => $invoices->pluck('id')->all()];
    })(),
    // One Stripe delivery of a checkout.session.completed event. 'slow:<s>' fakes a slow Stripe API.
    'stripe-event' => (function () use ($mark, $await, $arguments, $http, $pauseAfter, $signedStripeEvent, $stripeConfig, $directory, &$reported) {
        [$slot, $payload, $stripe] = $arguments;
        if (str_starts_with($stripe, 'slow:')) {
            $stripeConfig('sk_test_writer_policy_proof');
            Stripe\ApiRequestor::setHttpClient(new class((float) substr($stripe, 5), $directory) implements Stripe\HttpClient\ClientInterface
            {
                public function __construct(private float $delay, private string $markers) {}

                public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
                {
                    $path = (string) parse_url($absUrl, PHP_URL_PATH);
                    if ($method === 'get' && preg_match('#^/v1/payment_intents/(pi_[A-Za-z0-9_]+)$#', $path, $match)) {
                        file_put_contents($this->markers.'/stripe-called', '1');
                        usleep((int) ($this->delay * 1_000_000));

                        return [json_encode(['id' => $match[1], 'object' => 'payment_intent', 'status' => 'succeeded',
                            'payment_method' => 'pm_writer_policy_proof', 'latest_charge' => ['id' => 'ch_writer_policy_proof',
                                'object' => 'charge', 'receipt_url' => 'https://pay.stripe.com/receipts/writer-policy-proof',
                                'receipt_number' => '1000-0001', 'amount' => 10000, 'created' => 1795000000,
                                'billing_details' => ['email' => 'client@example.test'],
                                'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']]]],
                            JSON_THROW_ON_ERROR), 200, []];
                    }
                    file_put_contents($this->markers.'/stripe-unexpected', $method.' '.$path);

                    return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Not faked.']]), 404, []];
                }
            });
        } else {
            $stripeConfig(null);
        }
        // The duplicate check inside the payment transaction; pause after it.
        $pauseAfter('/^select exists\(select \* from `payments` where/', 0.3, 1);
        $mark('ready-'.$slot);
        $await('go', 150);
        $response = $http('POST', '/api/webhooks/stripe', server: $signedStripeEvent($payload), content: $payload);

        return ['status' => $response['status'], 'outcome' => $response['body']['outcome'] ?? null, 'error' => $reported];
    })(),
    // The shoot's client edits its notes while another request is busy with the same shoot.
    'notes-edit' => (function () use ($await, $arguments, $http) {
        [$shootId, $userId, $marker] = $arguments;
        $user = App\Models\User::findOrFail((int) $userId);
        $await($marker, 150);
        $began = microtime(true);
        $response = $http('PATCH', '/api/shoots/'.$shootId.'/notes', ['shoot_notes' => 'Edited during the slow Stripe call'], $user);

        return ['status' => $response['status'], 'message' => $response['message'], 'seconds' => round(microtime(true) - $began, 3)];
    })(),
    // One run of the weekly automations for a rule: the scheduler's run, or an admin's "Run now".
    'automation-run' => (function () use ($mark, $await, $arguments, $pauseAfter) {
        [$slot, $ruleId, $force, $repId] = $arguments;
        Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-09-28 11:05:00', 'UTC'));
        App\Services\Messaging\OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $reports = Mockery::mock(App\Services\SalesReportService::class);
        $reports->shouldReceive('getLastCompletedWeek')->andReturn([Carbon\Carbon::parse('2026-09-20'), Carbon\Carbon::parse('2026-09-26')]);
        $reports->shouldReceive('generateWeeklyReportsForAllSalesReps')->andReturn(collect([[
            'sales_rep' => ['id' => (int) $repId], 'period' => ['start' => '2026-09-20', 'end' => '2026-09-26'],
            'summary' => ['total_shoots' => 3, 'completed_shoots' => 3, 'total_revenue' => 300, 'total_paid' => 0,
                'outstanding_balance' => 300]]]));
        $reports->shouldReceive('isSalesRep')->andReturnTrue();
        app()->instance(App\Services\SalesReportService::class, $reports);
        // The run reads its period row, then saves it; pause after the read.
        $pauseAfter('/from `automation_dispatches` where/', 0.5);
        $mark('ready-'.$slot);
        $await('go', 150);
        $code = Artisan::call('automations:run-system', ['--rule' => (int) $ruleId] + ($force === '1' ? ['--force' => true] : []));

        return ['exit' => $code, 'output' => trim(Artisan::output())];
    })(),
    // A change-feed writer: 'delayed' and 'rollback' hold a write open until released; 'second' writes once.
    'aryeo-write' => (function () use ($db, $mark, $await, $arguments) {
        [$role, $shootId] = $arguments;
        if ($role === 'second') {
            $await('a-wrote', 150);
            $db->table('shoots')->where('id', (int) $shootId)->update(['notes' => 'second writer']);
            $mark('b-done');

            return 'committed';
        }
        try {
            return $db->transaction(function () use ($db, $mark, $await, $role, $shootId) {
                $db->table('shoots')->where('id', (int) $shootId)->update(['notes' => $role.' writer']);
                $mark('a-wrote');
                $await('a-release', 150);
                if ($role === 'rollback') {
                    throw new DomainException('roll back');
                }

                return 'committed';
            });
        } catch (DomainException) {
            return 'rolled-back';
        }
    })(),
    default => throw new RuntimeException('Unknown worker mode.'),
};

echo json_encode(['mode' => $mode, 'result' => $result, 'busy' => $busy, 'seconds' => round(microtime(true) - $started, 3),
    'level' => $db->transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL;
