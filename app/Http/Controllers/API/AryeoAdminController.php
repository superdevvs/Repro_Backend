<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Models\Shoot;
use App\Services\Aryeo\AryeoCatalog;
use App\Services\Aryeo\AryeoJobs;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AryeoAdminController extends Controller
{
    public function __construct(private AryeoCatalog $catalog, private AryeoJobs $jobs) {}

    private function shoot(Request $r, int $id): Shoot
    {
        $shoot = Shoot::findOrFail($id);
        app(ShootAuthorizationSupport::class)->ensureShootAccess($shoot, $r->user());

        return $shoot;
    }

    private function connections(Shoot $shoot)
    {
        return AryeoConnection::where('enabled', true)->get()->filter(fn ($c) => in_array((int) $shoot->client_id, array_map('intval', $c->client_ids ?? [])));
    }

    public function panel(Request $r, int $shoot)
    {
        $r->validate(['unit_id' => 'nullable|integer']);
        $shoot = $this->shoot($r, $shoot);
        $unit = $r->filled('unit_id') ? $r->integer('unit_id') : null;
        if ($unit) {
            $shoot->units()->findOrFail($unit);
        }
        $connections = $this->connections($shoot);
        $orders = AryeoRequest::whereIn('connection_id', $connections->pluck('id'))->where('shoot_id', $shoot->id)->where('unit_id', $unit)->get();
        $unmatched = AryeoRequest::whereIn('connection_id', $connections->pluck('id'))->whereNull('shoot_id')->latest()->limit(100)->get();

        return response()->json([
            'configured' => $connections->isNotEmpty(),
            'connections' => $connections->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'online' => (bool) $c->last_seen_at?->gt(now()->subMinutes(2)), 'last_seen_at' => $c->last_seen_at, 'processing_enabled' => $c->processing_enabled,
                'shoot_enabled' => in_array((int) $shoot->id, $c->delivery_shoot_ids ?? [], true),
                'executor_ready' => data_get($c->capabilities, 'processing_mode') === 'dashboard_jobs' && data_get($c->capabilities, 'shoot_executor') === true])->values(),
            'orders' => $orders->map(fn ($o) => [...$o->toArray(), 'readiness' => $this->catalog->readiness($shoot, $unit, $o->discovery['required'] ?? null),
                'jobs' => AryeoJob::where('request_record_id', $o->id)->latest()->limit(10)->get()]),
            'unmatched' => $unmatched->map(fn ($o) => ['id' => $o->id, 'source_id' => $o->source_id, 'address' => $o->discovery['address'], 'requester_email' => $o->discovery['requester_email'], 'unit_label' => $o->discovery['unit_label'] ?? null]),
        ]);
    }

    public function match(Request $r, int $shoot, int $order)
    {
        $r->validate(['unit_id' => 'nullable|integer', 'confirm_identity' => 'required|accepted']);
        $shoot = $this->shoot($r, $shoot);
        $unit = $r->filled('unit_id') ? $shoot->units()->findOrFail($r->integer('unit_id'))->id : null;
        abort_if($shoot->units()->count() > 1 && ! $unit, 422, 'Select the exact unit.');

        return response()->json(LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $unit, $order) {
            $record = AryeoRequest::whereIn('connection_id', $this->connections($shoot)->pluck('id'))->findOrFail($order);
            abort_if(AryeoJob::where('request_record_id', $order)->exists() || ($record->shoot_id && $record->shoot_id !== $shoot->id), 409, 'An existing match cannot be overwritten.');
            $record->update(['shoot_id' => $shoot->id, 'unit_id' => $unit, 'match_status' => 'matched']);

            return $record;
        }), 'aryeo.match'));
    }

    public function process(Request $r, int $shoot, int $order)
    {
        $shoot = $this->shoot($r, $shoot);
        $record = AryeoRequest::whereIn('connection_id', $this->connections($shoot)->pluck('id'))->where('shoot_id', $shoot->id)->findOrFail($order);

        return response()->json($this->jobs->enqueue($record, AryeoConnection::findOrFail($record->connection_id), $r->user()->id));
    }

    public function retry(Request $r, int $shoot, string $job)
    {
        $shoot = $this->shoot($r, $shoot);

        return response()->json(LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $job) {
            $row = AryeoJob::whereIn('connection_id', $this->connections($shoot)->pluck('id'))->findOrFail($job);
            abort_unless((int) $row->snapshot['shoot_id'] === $shoot->id, 404);
            abort_unless(in_array($row->status, ['failed', 'followup_pending', 'queued', 'reconciling']), 409, 'Job cannot be retried.');
            if (in_array($row->status, ['queued', 'reconciling'])) {
                return $row;
            }
            $row->update(['status' => 'queued', 'lease_expires_at' => null, 'claim_id' => null, 'error' => null]);

            return $row;
        }), 'aryeo.retry'));
    }
}
