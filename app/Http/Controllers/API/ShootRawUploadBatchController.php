<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\ShootRawUploadBatchService;
use Illuminate\Http\Request;

class ShootRawUploadBatchController extends Controller
{
    public function store(Request $request, Shoot $shoot, ShootRawUploadBatchService $batches)
    {
        if ($request->input('upload_type') === 'edited') {
            return app(ShootMediaController::class)->prepareUploadBatch($request, $shoot);
        }
        $request->merge([
            'type' => $request->input('type', $request->input('upload_type')),
            'batch_id' => $request->input('batch_id', $request->input('upload_batch_id')),
            'total_files' => $request->input('total_files', $request->input('upload_batch_total')),
            'service_id' => $request->input('service_id', $request->input('shoot_service_id')),
        ]);
        $data = $request->validate([
            'type' => ['required', 'in:raw'], 'batch_id' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9_-]+$/'],
            'total_files' => ['required', 'integer', 'min:1', 'max:10000'],
            'service_id' => ['nullable', 'integer', 'min:1'], 'upload_lane' => ['sometimes', 'in:photo,video'],
        ]);
        $batch = $batches->prepare($shoot, $request->user(), $data);

        return response()->json(['batch_id' => $batch->batch_id, 'upload_batch_id' => $batch->batch_id, 'upload_batch_total' => $batch->total_files, 'reserved_offset' => $batch->start_position, 'parallel_uploads' => $batches->parallelUploads($shoot, $batch)]);
    }
}
