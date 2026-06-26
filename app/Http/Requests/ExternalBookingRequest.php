<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExternalBookingRequest extends FormRequest
{
    /**
     * External API requests are authorized via middleware (ValidateExternalApiKey).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for external booking submissions.
     */
    public function rules(): array
    {
        return [
            // Client info (find-or-create by email)
            'client_name' => 'required|string|max:255',
            'client_email' => 'required|email|max:255',
            'client_phone' => 'nullable|string|max:30',
            'client_company' => 'nullable|string|max:255',

            // Property location
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:2',
            'zip' => 'required|string|max:10',

            // Services: array of service IDs (uses catalog prices)
            'services' => 'required|array|min:1',
            'services.*.id' => 'required|exists:services,id',
            'services.*.quantity' => 'nullable|integer|min:1',

            // Scheduling (optional — becomes hold-on if missing)
            'preferred_date' => 'nullable|date',
            'preferred_time' => 'nullable|string|max:10',

            // Alternate scheduling (optional — new external form fields)
            'alternate_date' => 'nullable|date',
            'alternate_time' => 'nullable|string|max:10',

            // Photographer selection — single (aliases) and list (aliases), all optional
            'selected_photographer_id' => 'nullable|integer|exists:users,id',
            'photographer_id' => 'nullable|integer|exists:users,id',
            'selected_photographers' => 'nullable|array',
            'selected_photographers.*' => 'integer|exists:users,id',
            'requested_photographers' => 'nullable|array',
            'requested_photographers.*' => 'integer|exists:users,id',

            // Explicit per-service assignments (optional)
            'service_assignments' => 'nullable|array',
            'service_assignments.*.service_id' => 'required_with:service_assignments.*|exists:services,id',
            'service_assignments.*.photographer_id' => 'nullable|integer|exists:users,id',
            'service_assignments.*.scheduled_date' => 'nullable|date',
            'service_assignments.*.scheduled_time' => 'nullable|string|max:10',

            // Property details (optional)
            'sqft' => 'nullable|integer|min:0',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|numeric|min:0',
            'mls_id' => 'nullable|string|max:50',

            // Notes
            'notes' => 'nullable|string|max:5000',

            // Source tracking
            'source' => 'nullable|string|max:100',

            // Account preference
            'create_account' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'client_name.required' => 'Client name is required.',
            'client_email.required' => 'Client email is required.',
            'client_email.email' => 'A valid email address is required.',
            'services.required' => 'At least one service must be selected.',
            'services.*.id.exists' => 'One or more selected services do not exist.',
            'address.required' => 'Property address is required.',
            'city.required' => 'City is required.',
            'state.required' => 'State is required.',
            'zip.required' => 'ZIP code is required.',
        ];
    }
}
