<?php

declare(strict_types=1);

// Fresh-process test helper. It reads only a synthetic replica and projects
// dispositions. It never invokes Laravel, Telnyx or a replay dispatcher.

use App\Support\TelnyxCutoverInbox;
use Illuminate\Encryption\Encrypter;

if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || $argc !== 4) {
    exit(2);
}

umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
[, $autoload, $directory, $reportName] = $argv;
$info = @stat($directory);
if ($info === false || realpath($directory) !== $directory || ! is_dir($directory)
    || dirname($directory) !== realpath(sys_get_temp_dir())
    || ! preg_match('/\Atelnyx-replay-drill-[a-f0-9]{16}\z/', basename($directory))
    || ($info['mode'] & 0777) !== 0700 || $info['uid'] !== posix_geteuid()
    || ! preg_match('/\Areport-[a-z]+\.json\z/', $reportName)) {
    exit(2);
}

try {
    require $autoload;
    $input = $directory.'/fixture-private.json';
    $inputInfo = @stat($input);
    if ($inputInfo === false || ! is_file($input) || is_link($input) || ($inputInfo['mode'] & 0777) !== 0600
        || $inputInfo['uid'] !== posix_geteuid() || $inputInfo['size'] > 8192) {
        throw new RuntimeException('fixture-input-unavailable');
    }
    $fixture = json_decode(file_get_contents($input), true, flags: JSON_THROW_ON_ERROR);
    $inbox = new TelnyxCutoverInbox(
        $directory.'/recovered',
        new Encrypter(base64_decode($fixture['cipher_key'], true), 'AES-256-GCM'),
        $fixture['public_key'],
    );
    $inventory = $inbox->reconciliationSnapshot(24);
    $expected = $fixture['acknowledged_receipts'];
    sort($expected, SORT_STRING);
    if (array_column($inventory, 'receipt_id') !== $expected) {
        throw new RuntimeException('inventory-mismatch');
    }

    usort($inventory, fn (array $a, array $b) =>
        [$a['received_at'], $a['receipt_id']] <=> [$b['received_at'], $b['receipt_id']]);
    $dispositions = [];
    $messages = [];
    foreach ($inventory as $receipt) {
        $id = $receipt['receipt_id'];
        if ($receipt['provider_event_conflict']) {
            $dispositions[$id] = 'manual-conflicting-event';
            continue;
        }
        if ($receipt['voice_review_required']) {
            $dispositions[$id] = 'manual-voice';
            continue;
        }
        $envelope = $inbox->open($id);
        $event = json_decode($envelope['body'], true, flags: JSON_THROW_ON_ERROR)['data'] ?? [];
        if ($receipt['destination'] !== 'messaging' || ($event['event_type'] ?? null) !== 'message.received') {
            $dispositions[$id] = 'manual-status-order';
            continue;
        }
        $messageId = $event['payload']['id'] ?? null;
        if (! is_string($messageId) || ! preg_match('/\Afixture-[a-z0-9-]{1,32}\z/', $messageId)) {
            $dispositions[$id] = 'manual-invalid-message';
            continue;
        }
        $dispositions[$id] = isset($messages[$messageId]) ? 'duplicate-message' : 'would-ingest';
        $messages[$messageId] = true;
    }
    ksort($dispositions, SORT_STRING);
    $counts = array_count_values($dispositions);
    ksort($counts, SORT_STRING);
    $private = fopen($directory.'/'.$reportName, 'xb');
    if ($private === false) {
        throw new RuntimeException('fixture-output-unavailable');
    }
    try {
        $report = json_encode(['receipts' => $dispositions, 'counts' => $counts], JSON_THROW_ON_ERROR)."\n";
        if (fwrite($private, $report) !== strlen($report) || ! fflush($private) || ! fsync($private)) {
            throw new RuntimeException('fixture-output-unavailable');
        }
    } finally {
        fclose($private);
    }
    echo json_encode(['result' => 'dry-run-verified', 'recovered' => count($inventory), 'counts' => $counts], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    $reason = $error->getMessage() === 'inventory-mismatch' ? 'inventory-mismatch' : 'fixture-refused';
    echo json_encode(['result' => 'REFUSED', 'reason' => $reason], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
