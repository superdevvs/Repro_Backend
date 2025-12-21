<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShootRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        
        if (!$user) {
            return false;
        }

        // Admin and super admin can book for any client
        if (in_array($user->role, ['admin', 'superadmin'])) {
            return true;
        }

        // Clients can only book for themselves
        if ($user->role === 'client') {
            // If client_id is provided, it must match the authenticated user
            $clientId = $this->input('client_id');
            return !$clientId || (int) $clientId === $user->id;
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = $this->user();
        $isAdmin = in_array($user->role ?? '', ['admin', 'superadmin']);

        return [
            // Client ID: required for admin, optional for client (defaults to auth user)
            'client_id' => [
                $isAdmin ? 'required' : 'nullable',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($user) {
                    if ($value && $user->role === 'client' && (int) $value !== $user->id) {
                        $fail('You can only book shoots for yourself.');
                    }
                },
            ],

            // Rep ID: optional, must exist if provided
            'rep_id' => 'nullable|exists:users,id',

            // Photographer: optional (becomes Hold-On if missing)
            'photographer_id' => 'nullable|exists:users,id',

            // Location fields
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:2',
            'zip' => 'required|string|max:10',

            // Services: required array with service_id and quantity
            'services' => 'required|array|min:1',
            'services.*.id' => 'required|exists:services,id',
            'services.*.quantity' => 'nullable|integer|min:1',

            // Scheduling: optional (becomes Hold-On if missing)
            'scheduled_at' => 'nullable|date',
            'time' => 'nullable|string|max:10', // Legacy support

            // Paywall and tax
            'bypass_paywall' => 'nullable|boolean',
            'tax_region' => 'nullable|string|in:md,dc,va,none',

            // Coupon code
            'coupon_code' => 'nullable|string|max:50',

            // Notes (optional)
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',

            // Package info (optional)
            'package_name' => 'nullable|string|max:255',
            'expected_final_count' => 'nullable|integer|min:0',
            'bracket_mode' => 'nullable|integer|in:3,5',
            'expected_raw_count' => 'nullable|integer|min:0',

            // Integration fields (optional)
            'mls_id' => 'nullable|string|max:50',
            'listing_source' => 'nullable|string|in:BrightMLS,Other',
            'property_details' => 'nullable|array',
            'is_private_listing' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Client is required for admin bookings.',
            'client_id.exists' => 'Selected client does not exist.',
            'services.required' => 'At least one service must be selected.',
            'services.*.id.exists' => 'One or more selected services do not exist.',
            'address.required' => 'Address is required.',
            'city.required' => 'City is required.',
            'state.required' => 'State is required.',
            'zip.required' => 'ZIP code is required.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        // For clients, automatically set client_id if not provided
        if ($user && $user->role === 'client' && !$this->has('client_id')) {
            $this->merge(['client_id' => $user->id]);
        }

        // Convert scheduled_date + time to scheduled_at if needed (legacy support)
        if ($this->has('scheduled_date') && !$this->has('scheduled_at')) {
            $date = $this->input('scheduled_date');
            $time = $this->input('time', '00:00:00');
            $this->merge(['scheduled_at' => "{$date} {$time}"]);
        }
    }
}

