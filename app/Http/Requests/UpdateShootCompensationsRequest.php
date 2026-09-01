<?php

namespace App\Http\Requests;

use App\Models\ShootCompensation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShootCompensationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(strtolower((string) $this->user()?->role), ['admin', 'superadmin'], true);
    }

    public function rules(): array
    {
        return [
            'compensations' => ['required', 'array', 'min:1'],
            'compensations.*.id' => ['required', 'integer', 'distinct', 'exists:shoot_compensations,id'],
            'compensations.*.mode' => ['required', Rule::in(ShootCompensation::MODES)],
            'compensations.*.amount' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
            'compensations.*.expected_updated_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('compensations', []) as $index => $row) {
                if (($row['mode'] ?? null) === ShootCompensation::MODE_CUSTOM
                    && ! is_numeric($row['amount'] ?? null)) {
                    $validator->errors()->add("compensations.{$index}.amount", 'Enter the exact custom compensation amount.');
                }
            }
        });
    }
}
