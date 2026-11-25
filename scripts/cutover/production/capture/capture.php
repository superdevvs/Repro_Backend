<?php

/**
 * Telnyx capture while the cutover hold is in force (design v2, 5.2).
 *
 * Installed root-owned as /opt/repro-telnyx-capture/capture.php, outside the
 * release, and served only by its own PHP-FPM master
 * (repro-telnyx-capture.service), which the freeze never drains or stops.
 * nginx sends the Telnyx webhook paths here while the open marker is missing
 * (repro-cutover-hold.conf); every other request is answered 503 by nginx.
 *
 * No kernel boot and no database. It loads the release's autoloader and
 * cached config (app key and cipher, Telnyx public key, signature tolerance,
 * spool path), checks each request as CaptureTelnyxDuringMaintenance does,
 * and saves it with the receiver's own TelnyxMaintenanceSpool, so
 * telnyx:replay-spool replays it like a text captured during a deploy. It
 * uses only what that class already offers in the release (fromConfig,
 * telnyxSignatureValid, whileCapturing, save).
 *
 * Texts (messaging, status, shared MMS) are answered 200 only after the
 * entry and its folder are flushed to disk. Voice events are saved for the
 * missed-call list and answered 503, so the provider's own retry and
 * failover are unchanged. Anything not verified or not saved is answered 503
 * with Retry-After, and Telnyx retries later.
 *
 * Settings: /etc/repro-telnyx-capture/capture.json (root, 0644), or the file
 * the pool names in env[REPRO_TELNYX_CAPTURE_SETTINGS]:
 *   {"app_root": "/var/www/backend", "ingress_open_marker": "/run/repro-cutover-ingress/open"}
 */

declare(strict_types=1);

use App\Support\TelnyxMaintenanceSpool;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Foundation\Application;

const REPRO_CAPTURE_SETTINGS = '/etc/repro-telnyx-capture/capture.json';
const REPRO_CAPTURE_REFUSED = '{"captured":false}';

/** Answers and ends the request. Every 503 carries Retry-After. */
function repro_capture_answer(int $status, string $body = REPRO_CAPTURE_REFUSED, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if ($status === 503) {
        header('Retry-After: 30');
    }
    foreach ($headers as $header) {
        header($header);
    }
    echo $body;
    exit;
}

/** @return array{app_root: string, ingress_open_marker: string} */
function repro_capture_settings(): array
{
    // Only the pool's own environment, never a FastCGI parameter.
    $file = getenv('REPRO_TELNYX_CAPTURE_SETTINGS', true) ?: REPRO_CAPTURE_SETTINGS;
    $raw = @file_get_contents($file);
    $settings = json_decode($raw === false ? '' : $raw, true, 4, JSON_THROW_ON_ERROR);
    $keys = is_array($settings) ? array_keys($settings) : [];
    sort($keys);
    if ($keys !== ['app_root', 'ingress_open_marker']) {
        throw new RuntimeException('Unexpected capture settings.');
    }
    foreach ($settings as $path) {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('Capture settings must be absolute paths.');
        }
    }

    return $settings;
}

/**
 * The receiver's Telnyx routes (CaptureTelnyxDuringMaintenance::ROUTES and
 * its shared-MMS pattern; Vapi is not routed here). Like the receiver, the
 * path is the request URI without its query string.
 *
 * @return array{0: string, 1: string, 2: ?string}|null [folder, route, route key]
 */
function repro_capture_route(string $requestUri): ?array
{
    $path = explode('?', $requestUri, 2)[0];
    if (preg_match('#\A/api/webhooks/telnyx/shared-mms/([\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12})\z#', $path, $match)) {
        return [TelnyxMaintenanceSpool::TEXTS, 'shared-mms', $match[1]];
    }

    return match ($path) {
        '/api/webhooks/telnyx/messaging' => [TelnyxMaintenanceSpool::TEXTS, 'messaging', null],
        '/api/webhooks/telnyx/status' => [TelnyxMaintenanceSpool::TEXTS, 'status', null],
        '/api/webhooks/telnyx/voice' => [TelnyxMaintenanceSpool::CALLS, 'voice', null],
        default => null,
    };
}

/**
 * The receiver's check (CaptureTelnyxDuringMaintenance::verifiedTelnyx): a
 * fresh timestamp, an Ed25519 signature over "timestamp|body" with the
 * configured key, and an event id. Behind FastCGI a repeated header cannot
 * be counted: nginx 1.23 and later join the values with ", ", which is not
 * base64, and older nginx passes the last one, which must still verify.
 *
 * @return array<string, string>|null The headers needed to re-verify later.
 */
function repro_capture_verified(string $body, int $arrivedAt): ?array
{
    $signature = $_SERVER['HTTP_TELNYX_SIGNATURE_ED25519'] ?? null;
    $timestamp = $_SERVER['HTTP_TELNYX_TIMESTAMP'] ?? null;
    if (! is_string($signature) || ! is_string($timestamp)) {
        return null;
    }
    $tolerance = max(1, (int) config('services.telnyx.webhook_tolerance_seconds', 300));
    if (! ctype_digit($timestamp) || abs($arrivedAt - (int) $timestamp) > $tolerance
        || ! TelnyxMaintenanceSpool::telnyxSignatureValid($body, $signature, $timestamp)) {
        return null;
    }
    $eventId = data_get(json_decode($body, true), 'data.id');
    if (! is_string($eventId) || $eventId === '') {
        return null;
    }

    return ['telnyx-signature-ed25519' => $signature, 'telnyx-timestamp' => $timestamp];
}

try {
    $settings = repro_capture_settings();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        repro_capture_answer(405, REPRO_CAPTURE_REFUSED, ['Allow: POST']);
    }
    $autoload = $settings['app_root'].'/vendor/autoload.php';
    $cachedConfig = $settings['app_root'].'/bootstrap/cache/config.php';
    if (! is_file($autoload) || ! is_file($cachedConfig)) {
        repro_capture_answer(503);
    }
    require $autoload;
    $arrivedAt = CarbonImmutable::now('UTC');

    // The release's cached config and the app's own encrypter binding, so
    // fromConfig() resolves the spool exactly as telnyx:replay-spool does.
    // No provider is booted and nothing here opens a database.
    $config = require $cachedConfig;
    if (! is_array($config)) {
        repro_capture_answer(503);
    }
    $app = new Application($settings['app_root']);
    $app->instance('config', new Repository($config));
    $app->register(EncryptionServiceProvider::class);

    $target = repro_capture_route((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($target === null) {
        repro_capture_answer(503);
    }
    [$folder, $route, $routeKey] = $target;
    $maxBytes = (int) config('services.telnyx.maintenance_capture.max_body_bytes', 1048576);
    $body = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    $headers = $body === '' || strlen($body) > $maxBytes ? null : repro_capture_verified($body, $arrivedAt->getTimestamp());
    if ($headers === null) {
        repro_capture_answer(503);
    }

    $spool = TelnyxMaintenanceSpool::fromConfig();
    $marker = $settings['ingress_open_marker'];
    $saved = $spool->whileCapturing(function () use ($spool, $marker, $folder, $route, $routeKey, $body, $headers, $arrivedAt): bool {
        // Checked again under the capture lock. A reopen creates the marker
        // and then replays, and the replay waits for every capture holding
        // this lock: a capture either saves before that replay lists the
        // spool, or finds the marker here and is refused, and Telnyx retries
        // against the open site.
        clearstatcache();
        if (file_exists($marker)) {
            return false;
        }
        $spool->save($folder, $route, $routeKey, $body, $headers, $arrivedAt);

        return true;
    });
} catch (Throwable $error) {
    // The class only: messages can carry paths or request data.
    error_log('repro-telnyx-capture: not saved ('.$error::class.')');
    repro_capture_answer(503);
}
if (! $saved) {
    repro_capture_answer(503);
}
if ($folder === TelnyxMaintenanceSpool::TEXTS) {
    repro_capture_answer(200, '{"status":"captured"}');
}
repro_capture_answer(503, '{"captured":"call"}');
