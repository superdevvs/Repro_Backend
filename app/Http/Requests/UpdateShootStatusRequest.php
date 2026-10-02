<?php

namespace App\Http\Requests;

use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShootStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $shoot = $this->route('shoot');
        if (! $user || ! $shoot instanceof Shoot) {
            return false;
        }

        return app(ShootAuthorizationSupport::class)->canScheduleShoot($shoot, $user);
    }

    public function rules(): array
    {
        return [
            // Schedule-on-resume may send scheduled_at and/or local date+time.
            'scheduled_at' => 'nullable|date',
            'scheduled_date' => 'nullable|date',
            'time' => 'nullable|string|max:10',
            'photographer_id' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:500',
            'travel_location_confirmed' => 'nullable|boolean',
            'travel_override' => 'nullable|boolean',
            'travel_override_reason' => 'nullable|string|max:500',
            'expected_units_revision' => 'nullable|integer|min:0',
        ];
    }
}
