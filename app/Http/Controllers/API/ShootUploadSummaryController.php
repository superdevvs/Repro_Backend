<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootUploadAttempt;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\Request;

class ShootUploadSummaryController extends Controller
{
    public function index(Request $request, Shoot $shoot, ShootAuthorizationSupport $authorization)
    {
        $actor = $request->user();
        abort_unless($actor && in_array(strtolower($actor->role), [
            'admin', 'superadmin', 'editing_manager', 'photographer', 'salesrep', 'rep', 'representative',
        ], true), 403);
        $authorization->ensureShootAccess($shoot, $actor);
        $data = $request->validate(['unit_id' => ['nullable', 'string', 'regex:/^(property|[1-9][0-9]*)$/']]);
        $unitId = $data['unit_id'] ?? null;
        if ($unitId && $unitId !== 'property') {
            abort_unless($shoot->units()->whereKey($unitId)->exists(), 404);
        }

        // Reuse the canonical acknowledgement ledger. Pending, partial, failed,
        // or cancelled batches must never be described as a completed upload.
        $query = ShootUploadAttempt::where('shoot_id', $shoot->id)
            ->whereNotNull('upload_batch_id')->where('upload_batch_total', '>', 0)
            ->where('status', ShootUploadAttempt::STATUS_COMPLETED)->whereNotNull('completed_at')
            ->when(strtolower($actor->role) === 'photographer', function ($query) use ($shoot, $actor, $authorization) {
                $assigned = $shoot->serviceItems()->get()->filter(fn ($item) => $authorization->canPhotographerAccessServiceItem($shoot, $item->id, $actor))->pluck('id');
                $query->where(fn ($scope) => $scope->whereIn('shoot_service_id', $assigned)->orWhereNull('shoot_service_id'));
            })
            ->when($unitId, fn ($query) => $query->where(function ($scoped) use ($shoot, $unitId) {
                $scoped->whereIn('shoot_service_id', $shoot->serviceItems()
                    ->when($unitId === 'property', fn ($items) => $items->whereNull('shoot_unit_id'),
                        fn ($items) => $items->where('shoot_unit_id', $unitId))->select('id'));
                if ($unitId === 'property') {
                    $scoped->orWhereNull('shoot_service_id');
                }
            }));
        // Bound work by recent batch identities, not individual files: truncating
        // file rows could turn a large successful batch into an incomplete one.
        $recent = (clone $query)->select(['actor_id', 'upload_type', 'upload_batch_id'])
            ->groupBy('actor_id', 'upload_type', 'upload_batch_id')->orderByRaw('MAX(completed_at) DESC')->limit(20)->get();
        if ($recent->isEmpty()) {
            return response()->json(['data' => []])->header('Cache-Control', 'private, no-store');
        }
        $attempts = $query->where(function ($batchQuery) use ($recent) {
            foreach ($recent as $batch) {
                $batchQuery->orWhere(fn ($identity) => $identity
                    ->where('actor_id', $batch->actor_id)->where('upload_type', $batch->upload_type)
                    ->where('upload_batch_id', $batch->upload_batch_id));
            }
        })->get(['id', 'actor_id', 'upload_type', 'upload_batch_id', 'upload_batch_index',
            'upload_batch_total', 'status', 'completed_at', 'result_file_ids', 'result_payload']);

        $summaries = $attempts->groupBy(fn ($attempt) => $attempt->actor_id.':'.$attempt->upload_type.':'.$attempt->upload_batch_id)
            ->map(function ($batch, $id) {
                $total = (int) $batch->max('upload_batch_total');
                $accepted = $batch->filter(fn ($attempt) => $attempt->status === ShootUploadAttempt::STATUS_COMPLETED
                    && $attempt->completed_at && ! empty($attempt->result_file_ids)
                    && empty($attempt->result_payload['errors'])
                    && (int) ($attempt->result_payload['error_count'] ?? 0) === 0);
                $slots = $accepted->pluck('upload_batch_index')->filter(fn ($index) => $index !== null && $index >= 0 && $index < $total)->unique();
                if ($slots->count() !== $total) {
                    return null;
                }
                $files = $accepted->flatMap(fn ($attempt) => $attempt->result_payload['uploaded_files'] ?? [])
                    ->filter(fn ($file) => is_array($file) && isset($file['id']))->unique('id');
                if ($files->count() < $total) {
                    return null;
                }

                return [
                    'id' => $id,
                    'uploadType' => $accepted->first()->upload_type,
                    'fileCount' => $files->count(),
                    'totalBytes' => $files->sum(fn ($file) => (int) ($file['fileSize'] ?? $file['file_size'] ?? 0)),
                    'completedAt' => $accepted->max('completed_at')->toISOString(),
                ];
            })->filter()->sortByDesc('completedAt')->take(20)->values();

        return response()->json(['data' => $summaries])->header('Cache-Control', 'private, no-store');
    }
}
