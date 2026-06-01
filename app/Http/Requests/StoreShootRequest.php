<?php

namespace App\Http\Requests;

use App\Models\Shoot;
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

        $userRole = strtolower($user->role ?? '');

        // Admin, super admin, editing manager, and sales reps can book for any client
        if (in_array($userRole, ['admin', 'superadmin', 'editing_manager', 'salesrep', 'sales_rep'])) {
            return true;
        }

        // Clients can only book for themselves
        if ($userRole === 'client') {
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
        $userRole = strtolower((string) ($user->role ?? ''));
        $isInternalScheduler = in_array($userRole, ['admin', 'superadmin', 'editing_manager', 'salesrep', 'sales_rep'], true);
        $shootType = (string) $this->input('shoot_type', 'standard');
        $canOmitServices = $isInternalScheduler && in_array($shootType, Shoot::INTERNAL_NO_CHARGE_SHOOT_TYPES, true);

        return [
            // Client ID: required for admin, optional for client (defaults to auth user)
            'client_id' => [
                $isInternalScheduler ? 'required' : 'nullable',
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

            'shoot_type' => [
                'nullable',
                Rule::in([
                    Shoot::SHOOT_TYPE_STANDARD,
                    Shoot::SHOOT_TYPE_COMPLIMENTARY,
                    Shoot::SHOOT_TYPE_SAMPLE_UPLOAD,
                    Shoot::SHOOT_TYPE_INTERNAL_TEST,
                    Shoot::SHOOT_TYPE_PRICING_PENDING,
                ]),
            ],
            'product_status' => [
                'nullable',
                Rule::in([
                    Shoot::PRODUCT_STATUS_HAS_PRODUCT,
                    Shoot::PRODUCT_STATUS_NO_PRODUCT,
                    Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT,
                ]),
            ],

            // Services: required array with service_id, quantity, and price
            'services' => [$canOmitServices ? 'nullable' : 'required', 'array', $canOmitServices ? 'min:0' : 'min:1'],
            'services.*.id' => 'required|exists:services,id',
            'services.*.quantity' => 'nullable|integer|min:1',
            'services.*.price' => 'nullable|numeric|min:0',
            'services.*.photographer_id' => 'nullable|exists:users,id',
            'services.*.editor_id' => 'nullable|exists:users,id',
            'services.*.scheduled_at' => 'nullable|date',
            'services.*.is_deliverable' => 'nullable|boolean',

            // Service item details: optional override rows for per-service scheduling/roles.
            'service_items' => 'nullable|array',
            'service_items.*.service_id' => 'required_with:service_items|exists:services,id',
            'service_items.*.photographer_id' => 'nullable|exists:users,id',
            'service_items.*.editor_id' => 'nullable|exists:users,id',
            'service_items.*.scheduled_at' => 'nullable|date',
            'service_items.*.price' => 'nullable|numeric|min:0',
            'service_items.*.quantity' => 'nullable|integer|min:1',
            'service_items.*.is_deliverable' => 'nullable|boolean',
            'service_items.*.workflow_status' => [
                'nullable',
                Rule::in(['pending', 'scheduled', 'in_progress', 'ready', 'delivered', 'cancelled']),
            ],
            'service_items.*.delivery_status' => [
                'nullable',
                Rule::in(['not_started', 'ready', 'delivered', 'cancelled']),
            ],
            'service_items.*.force_unlock_delivery' => 'nullable|boolean',
            'service_items.*.unlock_reason' => 'nullable|string|max:2000',

            'service_photographers' => 'nullable|array',
            'service_photographers.*.service_id' => 'required_with:service_photographers|exists:services,id',
            'service_photographers.*.photographer_id' => 'nullable|exists:users,id',

            // Scheduling: optional (becomes Hold-On if missing)
            'scheduled_at' => 'nullable|date',
            'time' => 'nullable|string|max:10', // Legacy support

            // Paywall and tax
            'bypass_paywall' => 'nullable|boolean',
            'tax_region' => 'nullable|string|in:md,dc,va,none',
            'admin_adjusted_total_quote' => [
                $isInternalScheduler ? 'nullable' : 'prohibited',
                'numeric',
                'min:0',
            ],

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
            'listing_type' => 'nullable|string|in:for_sale,for_rent',
            'property_status' => 'nullable|string|in:available,coming_soon,pending,sold,rented',
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
