<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use App\Services\Scheduling\DaySchedulePlan;
use App\Services\Scheduling\TravelScheduleAccess;
use Illuminate\Http\Request;

class DayScheduleController extends Controller
{
    public function __invoke(Request $request, DaySchedulePlan $schedule)
    {
        $data = $request->validate([
            'photographer_id' => 'required|integer|exists:users,id',
            'date' => 'required|date_format:Y-m-d', 'timezone' => 'required|timezone:all_with_bc',
            'shoot_id' => 'nullable|integer|exists:shoots,id',
        ]);
        $shoot = isset($data['shoot_id']) ? Shoot::findOrFail($data['shoot_id']) : null;
        app(TravelScheduleAccess::class)->authorizePayload([], $shoot, $request->user());

        return response()->json(['data' => $schedule->day($data['photographer_id'], $data['date'],
            $data['timezone'], $request->user(), $shoot?->id)]);
    }
}
