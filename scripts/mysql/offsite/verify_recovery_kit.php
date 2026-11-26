<?php

declare(strict_types=1);

// Check a restored recovery kit and decrypt one stored Laravel value with its APP_KEY.
// Usage: php verify_recovery_kit.php RESTORE_OUTPUT_DIR CIPHERTEXT_FILE EXPECTED_PLAINTEXT_SHA256
// RESTORE_OUTPUT_DIR is the restore_backup.py output (it holds artifacts/kit.json).
// Output never contains the key, the plaintext or any other .env value.

function kitRefuse(string $reason): never
{
    echo json_encode(['result' => 'REFUSED', 'reason' => $reason]), "\n";
    exit(1);
}

function kitRead(string $path, int $limit): string
{
    if (is_link($path) || ! is_file($path) || filesize($path) > $limit) {
        kitRefuse('kit file is missing, linked or too large');
    }
    $data = file_get_contents($path);

    return $data === false ? kitRefuse('kit file is unreadable') : $data;
}

if ($argc !== 4 || preg_match('/^[0-9a-f]{64}$/', $argv[3]) !== 1) {
    kitRefuse('usage: RESTORE_OUTPUT_DIR CIPHERTEXT_FILE EXPECTED_PLAINTEXT_SHA256');
}
$artifacts = rtrim($argv[1], '/').'/artifacts';
$meta = json_decode(kitRead($artifacts.'/kit.json', 65536), true);
if (! is_array($meta) || ($meta['schema'] ?? null) !== 'repro.recovery-kit.v1' || ! is_array($meta['files'] ?? null)) {
    kitRefuse('kit metadata is invalid');
}
$verified = 0;
$env = null;
foreach ($meta['files'] as $file) {
    $name = $file['name'] ?? '';
    if (! is_string($name) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $name) !== 1) {
        kitRefuse('kit metadata names an unsafe file');
    }
    $data = kitRead($artifacts.'/'.$name, 1048576);
    if (strlen($data) !== ($file['bytes'] ?? -1) || ! hash_equals((string) ($file['sha256'] ?? ''), hash('sha256', $data))) {
        kitRefuse('kit file hash differs');
    }
    $verified++;
    if ($name === 'app.env') {
        $env = $data;
    }
}
if ($env === null || preg_match('/^APP_KEY=["\']?base64:([A-Za-z0-9+\/]{43}=)["\']?\r?$/m', $env, $match) !== 1) {
    kitRefuse('kit has no usable APP_KEY');
}
$key = base64_decode($match[1], true);
unset($env, $match);
$payload = json_decode((string) base64_decode(trim(kitRead($argv[2], 1048576)), true), true);
if (! is_array($payload) || ! is_string($payload['iv'] ?? null) || ! is_string($payload['value'] ?? null)) {
    kitRefuse('ciphertext is not a Laravel encrypted payload');
}
$iv = base64_decode($payload['iv'], true);
$tag = (string) ($payload['tag'] ?? '');
if ($tag === '') {
    $mac = hash_hmac('sha256', $payload['iv'].$payload['value'], $key);
    $plain = is_string($payload['mac'] ?? null) && hash_equals($mac, $payload['mac'])
        ? openssl_decrypt($payload['value'], 'aes-256-cbc', $key, 0, (string) $iv)
        : false;
} else {
    $plain = openssl_decrypt($payload['value'], 'aes-256-gcm', $key, 0, (string) $iv, (string) base64_decode($tag, true));
}
if (! is_string($plain)) {
    kitRefuse('stored value did not decrypt with the recovered key');
}
$digest = hash('sha256', $plain);
unset($plain, $key);
if (! hash_equals($argv[3], $digest)) {
    kitRefuse('decrypted value differs from the expected hash');
}
echo json_encode(['result' => 'stored-value-decrypted', 'plaintext_sha256' => $digest, 'kit_files_verified' => $verified]), "\n";
