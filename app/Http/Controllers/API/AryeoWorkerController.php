<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Services\Aryeo\AryeoCatalog;
use App\Services\Aryeo\AryeoDiscovery;
use App\Services\Aryeo\AryeoJobs;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootFileAccessService;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AryeoWorkerController extends Controller
{
    public function __construct(private AryeoCatalog $catalog, private AryeoJobs $jobs) {}

    private function connection(Request $r): AryeoConnection
    {
        return $r->attributes->get('aryeo_connection');
    }

    public function heartbeat(Request $r)
    {
        $data = $r->validate(['processing_mode' => 'required|in:dashboard_jobs,automatic', 'shoot_executor' => 'required|boolean', 'inventory_refresh' => 'required|boolean', 'version' => 'required|string|max:80']);
        $c = $this->connection($r);
        $c->update(['last_seen_at' => now(), 'capabilities' => $data]);

        return response()->json(['processing_mode' => 'dashboard_jobs', 'processing_enabled' => $c->processing_enabled, 'lease_seconds' => 90, 'poll_seconds' => 15]);
    }

    public function shoots(Request $r)
    {
        $data = $r->validate(['search' => 'nullable|string|max:200', 'after_id' => 'nullable|integer|min:0']);
        $q = $this->catalog->query($this->connection($r))->with(['client', 'units'])->where('id', '>', $data['after_id'] ?? 0);
        if (! empty($data['search'])) {
            $q->where('address', 'like', '%'.$data['search'].'%');
        }
        $rows = $q->orderBy('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($s) => $this->catalog->identity($s)), 'next_after_id' => $rows->last()?->id]);
    }

    public function show(Request $r, int $shoot)
    {
        return response()->json($this->catalog->identity($this->catalog->shoot($this->connection($r), $shoot)));
    }

    public function readiness(Request $r, int $shoot)
    {
        $r->validate(['unit_id' => 'nullable|integer', 'request_record_id' => 'nullable|integer']);
        $order = $r->filled('request_record_id') ? AryeoRequest::where('connection_id', $this->connection($r)->id)->where('shoot_id', $shoot)->findOrFail($r->integer('request_record_id')) : null;
        $unit = $order ? $order->unit_id : ($r->filled('unit_id') ? $r->integer('unit_id') : null);

        return response()->json($this->catalog->readiness($this->catalog->shoot($this->connection($r), $shoot), $unit, $order?->discovery['required'] ?? null));
    }

    public function changes(Request $r)
    {
        $r->validate(['cursor' => 'nullable|integer|min:0']);
        $rows = DB::table('aryeo_changes')->whereIn('client_id', $this->connection($r)->client_ids)->where('id', '>', $r->integer('cursor'))->orderBy('id')->limit(100)->get();

        return response()->json(['data' => $rows, 'next_cursor' => $rows->last()?->id ?? $r->integer('cursor')]);
    }

    public function discover(Request $r, AryeoDiscovery $discovery)
    {
        $data = $r->validate([
            'source_id' => 'required|string|max:200', 'request_id' => 'nullable|string|max:200', 'listing_id' => 'nullable|string|max:200',
            'address' => 'required|string|max:255', 'requester_email' => 'required|email|max:255', 'unit_label' => 'nullable|string|max:100',
            'scheduled_date' => 'nullable|date_format:Y-m-d', 'summary_present' => 'required|boolean',
            'required' => 'required|array:photos,floorplans,videos,tours', 'required.photos' => 'required|integer|min:0|max:2000',
            'required.floorplans' => 'required|integer|min:0|max:200', 'required.videos' => 'required|integer|min:0|max:100', 'required.tours' => 'required|integer|min:0|max:100',
        ]);

        return response()->json(LockedWrite::run(fn () => DB::transaction(fn () => $discovery->record($this->connection($r), $data)), 'aryeo.discovery'));
    }

    public function inventory(Request $r, int $order)
    {
        $record = AryeoRequest::where('connection_id', $this->connection($r)->id)->findOrFail($order);
        if ($record->shoot_id) {
            $this->catalog->shoot($this->connection($r), $record->shoot_id);
        }
        $data = $r->validate([
            'request_id' => 'required|string|max:200', 'listing_id' => 'nullable|string|max:200',
            'checked_at' => 'required|date|before_or_equal:now|after:-10 minutes', 'complete' => 'required|boolean',
            'assets' => 'present|array|max:3000', 'assets.*.id' => 'required|string|max:200', 'assets.*.type' => 'required|in:photos,floorplans,videos,tours',
            'assets.*.filename' => 'nullable|string|max:255', 'assets.*.delivered' => 'required|boolean', 'assets.*.dashboard_asset_id' => 'nullable|integer',
        ]);
        abort_unless($record->request_id === $data['request_id'], 409, 'Inventory request mismatch.');
        abort_if($record->listing_id && $record->listing_id !== ($data['listing_id'] ?? null), 409, 'Inventory listing mismatch.');
        abort_if($record->inventory_checked_at && $record->inventory_checked_at->gt($data['checked_at']), 409, 'Older inventory cannot replace newer inventory.');
        $record->update(['inventory' => $data, 'inventory_checked_at' => $data['checked_at'], 'listing_id' => $data['listing_id'] ?? $record->listing_id]);

        return response()->json($record);
    }

    public function claim(Request $r)
    {
        $data = $r->validate(['claim_id' => 'required|uuid', 'lease_token' => 'required|string|min:32|max:200']);
        $job = $this->jobs->claim($this->connection($r), $data['claim_id'], $data['lease_token']);

        return response()->json(['job' => $job, 'reconciliation_required' => $job?->status === 'reconciling']);
    }

    public function job(Request $r, string $job)
    {
        $row = AryeoJob::where('connection_id', $this->connection($r)->id)->findOrFail($job);
        $this->catalog->shoot($this->connection($r), $row->snapshot['shoot_id']);

        return response()->json($row);
    }

    public function renew(Request $r, string $job)
    {
        $data = $r->validate(['lease_token' => 'required|string', 'phase' => 'nullable|in:preparing,uploading,verifying,delivering,followup',
            'reconciliation' => 'nullable|in:not_delivered,delivered,unknown']);

        return response()->json(LockedWrite::run(fn () => DB::transaction(function () use ($r, $job, $data) {
            $row = $this->jobs->leased($this->connection($r), $job, $data['lease_token']);
            if ($row->status === 'reconciling' && isset($data['reconciliation']) && $data['reconciliation'] !== 'unknown') {
                $order = AryeoRequest::findOrFail($row->request_record_id);
                abort_unless($order->inventory_checked_at?->gt(now()->subMinutes(2)) && ($order->inventory['complete'] ?? false), 409, 'Refresh the complete remote inventory before reconciliation.');
                // A delivered result must be recorded with a receipt, not replayed.
                if ($data['reconciliation'] === 'not_delivered') {
                    $row->status = 'running';
                }
            }
            $row->lease_expires_at = now()->addSeconds(90);
            if (isset($data['phase'])) {
                $row->error = 'Progress: '.$data['phase'];
            }
            $row->save();

            return $row;
        }), 'aryeo.renew'));
    }

    public function authorize(Request $r, string $job)
    {
        $r->validate(['lease_token' => 'required|string']);
        $row = $this->jobs->leased($this->connection($r), $job, $r->string('lease_token')->toString());

        return response()->json($this->jobs->authorizeDelivery($row, $this->connection($r)));
    }

    public function result(Request $r, string $job)
    {
        $data = $r->validate([
            'lease_token' => 'required|string', 'steps' => 'required|array:upload,delivery,completion_email,forwarding,summary_forwarding,filing',
            'steps.*' => 'required|in:pending,success,failed,not_applicable', 'error' => 'nullable|string|max:2000',
            'receipt' => 'nullable|array:request_id,listing_id,media_version,verified_at,asset_ids,tour_ids',
            'receipt.request_id' => 'required_with:receipt|string|max:200', 'receipt.listing_id' => 'required_with:receipt|string|max:200',
            'receipt.media_version' => 'required_with:receipt|string|size:64', 'receipt.verified_at' => 'required_with:receipt|date|before_or_equal:now',
            'receipt.asset_ids' => 'sometimes|array', 'receipt.asset_ids.*' => 'integer', 'receipt.tour_ids' => 'sometimes|array', 'receipt.tour_ids.*' => 'string|max:100',
        ]);

        return response()->json(LockedWrite::run(fn () => DB::transaction(function () use ($r, $job, $data) {
            $row = AryeoJob::where('connection_id', $this->connection($r)->id)->findOrFail($job);
            $this->catalog->shoot($this->connection($r), $row->snapshot['shoot_id']);
            // GET job recovers unknown outcomes. An identical result retry is safe.
            if (in_array($row->status, ['completed', 'followup_pending', 'failed'])
                && hash_equals((string) $row->lease_hash, hash('sha256', $data['lease_token']))
                && array_diff_assoc($data['steps'], $row->steps ?? []) === []
                && ($data['receipt'] ?? $row->receipt) == $row->receipt) {
                return $row;
            }
            $row = $this->jobs->leased($this->connection($r), $job, $data['lease_token']);
            abort_if(($data['steps']['delivery'] ?? '') === 'not_applicable', 422, 'Delivery cannot be skipped.');
            $order = AryeoRequest::findOrFail($row->request_record_id);
            if (! ($order->discovery['summary_present'] ?? false)) {
                $data['steps']['summary_forwarding'] = 'not_applicable';
            }

            return $this->jobs->result($row, $data);
        }), 'aryeo.result'));
    }

    public function download(Request $r, string $job, int $asset)
    {
        $r->validate(['lease_token' => 'required|string']);
        $row = $this->jobs->leased($this->connection($r), $job, $r->string('lease_token')->toString());
        $ready = $this->jobs->authorizeDelivery($row, $this->connection($r));
        abort_unless(collect($ready['assets'])->contains('id', $asset), 404);
        $shoot = $this->catalog->shoot($this->connection($r), $row->snapshot['shoot_id']);

        return $this->original($shoot, $asset);
    }

    public function approvedOriginal(Request $r, int $order, int $asset)
    {
        $record = AryeoRequest::where('connection_id', $this->connection($r)->id)->findOrFail($order);
        abort_unless($record->match_status === 'matched', 409, 'An exact match is required.');
        $shoot = $this->catalog->shoot($this->connection($r), $record->shoot_id);
        $ready = $this->catalog->readiness($shoot, $record->unit_id, $record->discovery['required'] ?? null);
        abort_unless($ready['eligible'] && collect($ready['assets'])->contains('id', $asset), 403, 'Original is not released.');

        return $this->original($shoot, $asset);
    }

    private function original(\App\Models\Shoot $shoot, int $asset)
    {
        $file = $shoot->files()->findOrFail($asset);
        $access = app(ShootFileAccessService::class);
        foreach ([$file->storage_path, $file->path] as $path) {
            if (! $path) {
                continue;
            }
            $local = $access->resolveLocalPath($path);
            if ($local && is_file($local)) {
                return app(MediaStorage::class)->localResponse($local, basename($file->filename), ['Cache-Control' => 'private, no-store', 'X-Content-SHA256' => hash_file('sha256', $local)], $shoot->id);
            }
            $temporary = $access->downloadStoredFileToTemp($path);
            if ($temporary && is_file($temporary)) {
                return app(MediaStorage::class)->temporaryDownload($temporary, basename($file->filename), ['Cache-Control' => 'private, no-store', 'X-Content-SHA256' => hash_file('sha256', $temporary)], $shoot->id);
            }
        }
        abort(404, 'Original file unavailable. Preview files are never substituted.');
    }
}
