<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AryeoApiInstallation;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AryeoWebhookController extends Controller
{
    public function __invoke(Request $request, AryeoApiInstallation $installation)
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > 1048576, 413);
        $timestamp = $request->header('Timestamp', '');
        $signature = $request->header('Signature', '');
        $secret = config('aryeo_api.webhook_secrets', [])[$installation->id] ?? null;
        abort_unless($secret && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300
            && preg_match('/^[a-f0-9]{64}$/D', $signature)
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature), 401);
        $event = json_decode($raw, true);
        abort_unless(is_array($event) && ($event['specversion'] ?? '') === '1.0'
            && ($event['source'] ?? '') === config('aryeo_api.webhook_source')
            && $request->header('Source') === $event['source']
            && is_string($event['id'] ?? null) && strlen($event['id']) <= 255
            && hash_equals($event['id'], $request->header('Idempotency-Key', '')), 422);
        abort_unless(($event['type'] ?? '') === 'delivery.created', 422);
        $hash = hash('sha256', $raw);
        LockedWrite::run(fn () => DB::transaction(function () use ($event, $hash, $installation) {
            $existing = DB::table('aryeo_api_webhooks')->where('event_id', $event['id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->body_hash, $hash) && $existing->installation_id === $installation->id, 409);

                return;
            }
            DB::table('aryeo_api_webhooks')->insert(['event_id' => $event['id'], 'installation_id' => $installation->id,
                'type' => $event['type'], 'body_hash' => $hash, 'payload' => Crypt::encryptString(json_encode($event, JSON_THROW_ON_ERROR)), 'received_at' => now()]);
            \App\Jobs\ReconcileAryeoWebhook::dispatch($event['id'])->afterCommit();
        }), 'aryeo-api.webhook');

        return response()->json(['received' => true], 202);
    }
}
