<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShootStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        // Only admin, super admin, photographer (for their shoots), and editor can update status
        return in_array($user->role, ['admin', 'superadmin', 'super_admin', 'photographer', 'editor']);
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => 'nullable|date',
            'photographer_id' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ];
    }
}

