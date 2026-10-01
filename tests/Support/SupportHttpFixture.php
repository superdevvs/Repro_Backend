<?php

// Local browser acceptance fixture. Never run against a business database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== '/tmp/repro-support-qa.sqlite') {
    throw new RuntimeException('This fixture requires the isolated /tmp support QA database.');
}
touch('/tmp/repro-support-qa.sqlite');
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$records = [];
foreach (['admin', 'client', 'photographer', 'salesRep'] as $role) {
    $user = App\Models\User::factory()->create([
        'role' => $role, 'name' => 'QA '.ucfirst($role), 'email' => $role.'@support-qa.example.test',
        'email_verified_at' => now(), 'account_status' => 'active',
        'metadata' => ['terms_accepted_at' => now()->toIso8601String()],
    ]);
    $records[$role] = ['id' => $user->id, 'user' => $user->toArray(), 'token' => $user->createToken('local-support-acceptance')->plainTextToken];
}
$client = App\Models\User::find($records['client']['id']);
$service = app(App\Services\SupportTicketService::class);
for ($i = 1; $i <= 24; $i++) {
    $service->create($client, [
        'request_key' => (string) Illuminate\Support\Str::uuid(), 'subject' => 'QA existing request '.$i,
        'category' => 'delivery', 'body' => 'Synthetic issue for browser pagination acceptance '.$i.'.',
    ]);
}
file_put_contents('/qa/runtime-accounts.json', json_encode($records, JSON_PRETTY_PRINT));
echo "Isolated support QA fixture ready.\n";
