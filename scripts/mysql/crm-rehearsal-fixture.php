<?php

// Build a disposable SQLite snapshot from the release migrations for conversion parity checks.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || count($argv) !== 2
    || realpath(dirname($argv[1])) !== realpath(sys_get_temp_dir()) || ! str_ends_with($argv[1], '.sqlite')) {
    throw new RuntimeException('Use APP_ENV=testing and a new .sqlite file directly in the system temporary directory.');
}
$handle = fopen($argv[1], 'xb');
if (! $handle) {
    throw new RuntimeException('The fixture requires a new file.');
}
fclose($handle);
chmod($argv[1], 0600);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => realpath($argv[1])]);
DB::purge('sqlite');
DB::statement('PRAGMA journal_mode=DELETE');
foreach (glob(database_path('migrations/2026_11_24_*.php')) as $file) {
    if (preg_match('/_(crm|recruit)_/', basename($file))) {
        (require $file)->up();
    }
}
$contact = DB::table('crm_contacts')->insertGetId([
    'name' => 'Synthetic CRM contact', 'name_normalized' => 'synthetic crm contact',
    'segment' => 'residential', 'stage' => 'inquiry', 'email' => 'fixture@example.test',
    'review_reasons' => json_encode(['test_import'], JSON_THROW_ON_ERROR),
    'marketing_consent_email' => 'fixture@example.test', 'marketing_consent_at' => '2026-11-24 09:12:34',
]);
DB::table('crm_quotes')->insert([
    'contact_id' => $contact, 'number' => 'CRM-TEST-1', 'status' => 'accepted',
    'total' => 791.25, 'public_token' => str_repeat('q', 48), 'sqft' => 1500,
    'property_zip' => '02139', 'booking_status' => 'needs_review',
    'booking_review_reason' => 'Synthetic fixture',
]);
DB::table('crm_website_intake_receipts')->insert([
    'kind' => 'inquiry', 'reference' => 'fixture/reference:01',
    'payload' => json_encode(['message' => "Line one\nLine two · 東京", 'contact_id' => $contact], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
]);
DB::table('crm_sms_requests')->insert([
    'contact_id' => $contact, 'user_id' => 42, 'request_id' => 'b3639e1c-36e3-4ff7-a274-1c2fd6044469',
    'payload_hash' => hash('sha256', 'fixture'), 'status' => 'pending',
]);
DB::disconnect('sqlite');
echo "Synthetic CRM snapshot created.\n";
