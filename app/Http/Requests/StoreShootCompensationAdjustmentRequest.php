<?php

namespace App\Http\Requests;

use App\Models\ShootCompensation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShootCompensationAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(strtolower((string) $this->user()?->role), ['admin', 'superadmin'], true);
    }

    public function rules(): array
    {
        return [
            'line_type' => [
                'required',
                Rule::in([
                    ShootCompensation::LINE_TYPE_ADJUSTMENT,
                    ShootCompensation::LINE_TYPE_REVERSAL,
                ]),
            ],
            // The API always accepts a positive magnitude. Reversals are stored
            // as negative immutable payout lines by the server.
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99'],
            'note' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
