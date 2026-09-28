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
            'scheduled_at' => 'nullable|date',
            'photographer_id' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:500',
            'expected_units_revision' => 'nullable|integer|min:0',
        ];
    }
}
