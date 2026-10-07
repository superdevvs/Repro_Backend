<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\TravelScheduleAccess;
use App\Services\Shoots\MultiUnitBookingService;
use Illuminate\Http\Request;

class ScheduleFeasibilityController extends Controller
{
    public function __invoke(Request $request, ScheduleFeasibilityService $evaluator)
    {
        $request->merge(\App\Support\Timezone::scheduleInput($request->only(['timezone'])));
        $rules = array_merge(MultiUnitBookingService::rules(), \App\Services\Scheduling\DaySchedulePlan::rules(), [
            'shoot_id' => 'nullable|integer|exists:shoots,id',
            'client_id' => 'nullable|integer|exists:users,id',
            'photographer_id' => 'nullable|integer|exists:users,id',
            'scheduled_at' => 'nullable|date',
            'requested_date' => 'nullable|date_format:Y-m-d',
            'requested_time' => 'nullable|date_format:H:i',
            'timezone' => 'nullable|timezone:all_with_bc',
            'action_mode' => 'nullable|in:create,update,approve,schedule,assign,reschedule,alternate,additional_work',
            'include_alternatives' => 'sometimes|boolean',
            'travel_location_confirmed' => 'sometimes|boolean',
            'address' => 'sometimes|string|max:255',
            'city' => 'sometimes|string|max:255',
            'state' => 'sometimes|string|max:80',
            'zip' => 'sometimes|string|max:20',
            'property_details' => 'sometimes|array',
            'property_details.sqft' => 'nullable|integer|min:0|max:10000000',
            'property_details.squareFeet' => 'nullable|integer|min:0|max:10000000',
            'service_photographers' => 'sometimes|array|max:300',
            'service_photographers.*.service_id' => 'required|integer|exists:services,id',
            'service_photographers.*.photographer_id' => 'nullable|integer|exists:users,id',
            // Exclusions are derived exclusively from the authorized edited shoot.
            'exclude_shoot_id' => 'prohibited',
            'exclude_shoot_ids' => 'prohibited',
            '_schedule_visits' => 'prohibited',
        ]);
        foreach (['services' => 'id', 'service_items' => 'service_id'] as $field => $id) {
            $rules[$field] = 'sometimes|array|max:300';
            $rules[$field.'.*.'.$id] = 'required|integer|exists:services,id';
            $rules[$field.'.*.photographer_id'] = 'nullable|integer|exists:users,id';
            $rules[$field.'.*.scheduled_at'] = 'nullable|date';
            $rules[$field.'.*.duration_minutes'] = ['nullable', 'integer', 'min:0', 'max:300', new \App\Rules\ServiceDuration];
            $rules[$field.'.*.quantity'] = 'nullable|integer|min:1|max:1000';
            $rules[$field.'.*.price'] = 'nullable|numeric|min:0';
            $rules[$field.'.*.shoot_service_id'] = 'nullable|integer';
        }
        $data = $request->validate($rules);
        // Nested array validation retains unknown keys: only property size can affect
        // duration here; caller coordinates and verification metadata are untrusted.
        if (isset($data['property_details'])) {
            $data['property_details'] = \Illuminate\Support\Arr::only($data['property_details'], ['sqft', 'squareFeet']);
        }
        $shoot = ! empty($data['shoot_id']) ? Shoot::findOrFail($data['shoot_id']) : null;
        app(TravelScheduleAccess::class)->authorizePayload($data, $shoot, $request->user());
        $plans = app(\App\Services\Scheduling\DaySchedulePlan::class)->expand([['payload' => $data, 'shoot' => $shoot]], $request->user());
        $result = count($plans) > 1 ? $evaluator->evaluatePlans($plans, $request->user())
            : $evaluator->evaluate($data, $shoot, $request->user(), (bool) ($data['include_alternatives'] ?? false));

        return response()->json(['data' => $evaluator->publicResult($result)]);
    }
}
