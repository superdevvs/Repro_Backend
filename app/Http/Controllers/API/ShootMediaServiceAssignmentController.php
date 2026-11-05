<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\ShootMediaServiceAssignment;
use Illuminate\Http\Request;

class ShootMediaServiceAssignmentController extends Controller
{
    public function __invoke(Request $request, Shoot $shoot, ShootMediaServiceAssignment $assignment)
    {
        $data = $request->validate([
            'dry_run' => 'required|boolean',
            'file_ids' => 'sometimes|array|min:1|max:500', 'file_ids.*' => 'integer|distinct',
            'shoot_service_id' => 'sometimes|integer|min:1',
            'expected_target_unit_id' => 'sometimes|nullable|integer',
            'expected_files' => 'sometimes|array|min:1|max:500',
            'expected_files.*.id' => 'required|integer|distinct',
            'expected_files.*.shoot_service_id' => 'present|nullable|integer',
            'expected_files.*.content_version' => 'required|integer|min:1',
            'idempotency_key' => 'sometimes|string|min:8|max:191',
            'reason' => 'nullable|string|max:1000',
            'confirm_historical' => 'sometimes|boolean',
            'expected_receipt_revision' => 'sometimes|string|size:64',
        ]);
        return response()->json($assignment->execute($shoot, $request->user(), $data));
    }
}
