<?php

namespace App\Http\Controllers\API;

use App\Jobs\ProcessExternalShootRequestedJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExternalBookingRequest;
use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use App\Models\Service;
use App\Models\ServiceGroup;
use App\Models\User;
use App\Services\ShootTaxService;
use App\Services\ShootWorkflowService;
use App\Services\ShootActivityLogger;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\ShootMutationSupportService;
use App\Services\Users\ClientDashboardOnboardingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ExternalBookingController extends Controller
{
    protected ShootTaxService $taxService;
    protected ShootActivityLogger $activityLogger;
    protected AutomationService $automationService;
    protected ShootMutationSupportService $shootSupport;

    public function __construct(
        ShootTaxService $taxService,
        ShootActivityLogger $activityLogger,
        AutomationService $automationService,
        ShootMutationSupportService $shootSupport
    ) {
        $this->taxService = $taxService;
        $this->activityLogger = $activityLogger;
        $this->automationService = $automationService;
        $this->shootSupport = $shootSupport;
    }

    /**
     * Public endpoint for external sites (e.g. Lovable) to submit shoot booking requests.
     * Creates a shoot with "requested" status that appears in the dashboard for approval.
     *
     * POST /api/external/book-shoot
     * Header: X-API-Key: <your-key>
     */
    public function bookShoot(ExternalBookingRequest $request)
    {
        $validated = $request->validated();

        try {
            $result = DB::transaction(function () use ($validated, $request) {
                // 1. Find or create client by email
                $client = $this->findOrCreateClient($validated);
                $this->shootSupport->ensureClientCanBookServices($client->id, $validated['services']);

                // 2. Calculate pricing from service catalog and client defaults
                $services = $validated['services'];
                $pricingCalculation = $this->shootSupport->buildPricingCalculation(
                    $services,
                    $client,
                    $validated['state'],
                    null,
                    $validated['coupon_code'] ?? null
                );

                // 4. Build scheduled_at if preferred date/time provided
                $scheduledAt = null;
                if (!empty($validated['preferred_date'])) {
                    $time = $validated['preferred_time'] ?? '00:00';
                    $scheduledAt = new \DateTime("{$validated['preferred_date']} {$time}");
                }

                // 5. Look up client's rep from previous shoots
                $repId = $this->getClientRep($client->id);

                // 6. Build property_details from optional fields
                $propertyDetails = array_filter([
                    'sqft' => $validated['sqft'] ?? null,
                    'bedrooms' => $validated['bedrooms'] ?? null,
                    'bathrooms' => $validated['bathrooms'] ?? null,
                ]);

                // 7. Create the shoot as "requested"
                $source = $validated['source'] ?? 'external_website';
                $shoot = Shoot::create([
                    'client_id' => $client->id,
                    'rep_id' => $repId,
                    'photographer_id' => null,
                    'service_id' => $services[0]['id'], // Legacy support
                    'address' => $validated['address'],
                    'city' => $validated['city'],
                    'state' => $validated['state'],
                    'zip' => $validated['zip'],
                    'mls_id' => $validated['mls_id'] ?? null,
                    'property_details' => !empty($propertyDetails) ? $propertyDetails : null,
                    'scheduled_at' => $scheduledAt,
                    'scheduled_date' => $scheduledAt ? $scheduledAt->format('Y-m-d') : null,
                    'time' => $scheduledAt ? $scheduledAt->format('H:i') : null,
                    'status' => Shoot::STATUS_REQUESTED,
                    'workflow_status' => Shoot::STATUS_REQUESTED,
                    'base_quote' => $pricingCalculation['base_quote'],
                    'discount_type' => $pricingCalculation['discount_type'],
                    'discount_value' => $pricingCalculation['discount_value'],
                    'discount_amount' => $pricingCalculation['discount_amount'],
                    'tax_region' => $pricingCalculation['tax_region'],
                    'tax_percent' => $pricingCalculation['tax_percent'],
                    'tax_amount' => $pricingCalculation['tax_amount'],
                    'total_quote' => $pricingCalculation['total_quote'],
                    'bypass_paywall' => false,
                    'payment_status' => 'unpaid',
                    'created_by' => "External ({$source})",
                    'updated_by' => "External ({$source})",
                    'shoot_notes' => $validated['notes'] ?? null,
                ]);

                // 8. Attach services with catalog prices
                $this->shootSupport->attachServices($shoot, $services);

                if (!empty($pricingCalculation['coupon_code']) && $pricingCalculation['coupon_discount_amount'] > 0) {
                    $coupon = $this->shootSupport->resolveCoupon($pricingCalculation['coupon_code']);
                    if ($coupon) {
                        $coupon->increment('current_uses');
                    }
                }

                // 9. Log activity
                $this->activityLogger->log(
                    $shoot,
                    'shoot_requested',
                    [
                        'by' => $client->name,
                        'source' => $source,
                        'status' => Shoot::STATUS_REQUESTED,
                        'scheduled_at' => $scheduledAt ? \Carbon\Carbon::instance($scheduledAt)->toIso8601String() : null,
                    ],
                    null // No authenticated user for external requests
                );

                return [
                    'shoot' => $shoot,
                    'client' => $client,
                    'is_new_client' => $client->wasRecentlyCreated,
                ];
            });

            $shoot = $result['shoot'];

            ProcessExternalShootRequestedJob::dispatch($shoot->id)->afterCommit();

            return response()->json([
                'message' => 'Shoot request submitted successfully. It will be reviewed by our team.',
                'data' => [
                    'shoot_id' => $shoot->id,
                    'status' => 'requested',
                    'client_id' => $result['client']->id,
                    'is_new_client' => $result['is_new_client'],
                    'total_quote' => $shoot->total_quote,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('External booking failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $validated,
            ]);

            return response()->json([
                'message' => 'Failed to create booking. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Check if an email belongs to an existing client.
     * Returns exists=true + dashboard login URL if found.
     *
     * POST /api/external/check-client
     * Header: X-API-Key: <your-key>
     * Body: { "email": "john@example.com" }
     */
    public function checkClient(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($request->input('email')));
        $client = User::where('email', $email)->first();

        $dashboardUrl = rtrim(config('app.frontend_url', 'https://reprodashboard.com'), '/');

        if ($client) {
            return response()->json([
                'exists' => true,
                'client_name' => $client->name,
                'login_url' => $dashboardUrl . '/login',
                'message' => 'You already have an account. Please log in to book a shoot from your dashboard.',
            ]);
        }

        return response()->json([
            'exists' => false,
            'login_url' => $dashboardUrl . '/login',
            'message' => 'No existing account found. You can proceed with booking.',
        ]);
    }

    /**
     * List available services for the external site to display.
     * GET /api/external/services
     */
    public function services()
    {
        $services = Service::with('category')
            ->orderBy('category_id')
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'price' => (float) $s->price,
                'pricing_type' => $s->pricing_type,
                'category' => $s->category?->name,
                'category_id' => $s->category_id,
            ]);

        return response()->json(['data' => $services]);
    }

    protected function serviceGroupsFeatureAvailable(): bool
    {
        try {
            if (!class_exists(ServiceGroup::class)) {
                return false;
            }

            return ServiceGroup::isFeatureAvailable();
        } catch (\Throwable $exception) {
            Log::warning('Service groups unavailable in ExternalBookingController.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Find existing client by email or create a new one.
     */
    protected function findOrCreateClient(array $data): User
    {
        $email = strtolower(trim($data['client_email']));

        $client = User::where('email', $email)->first();

        if ($client) {
            // Update phone/company if they were empty
            $updated = false;
            if (empty($client->phone) && !empty($data['client_phone'])) {
                $client->phone = $data['client_phone'];
                $updated = true;
            }
            if (empty($client->company_notes) && !empty($data['client_company'])) {
                $client->company_notes = $data['client_company'];
                $updated = true;
            }
            if ($this->usersTableHasUsernameColumn() && empty($client->username)) {
                $client->username = $this->generateUniqueUsername($data['client_name'], $email);
                $updated = true;
            }
            if ($updated) {
                $client->save();
            }
            return $client;
        }

        // Create new client account with a random password (they'll reset it)
        return User::create([
            'name' => $data['client_name'],
            'username' => $this->generateUniqueUsername($data['client_name'], $email),
            'email' => $email,
            'phone' => $data['client_phone'] ?? null,
            'company_notes' => $data['client_company'] ?? null,
            'role' => 'client',
            'password' => Hash::make(Str::random(32)),
            'metadata' => app(ClientDashboardOnboardingService::class)->applyEligibility([], 'external_booking'),
        ]);
    }

    protected function generateUniqueUsername(string $name, string $email): string
    {
        $base = Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '.')
            ->trim('.')
            ->value();

        if ($base === '') {
            $base = Str::before($email, '@');
        }

        $base = trim(preg_replace('/[^a-z0-9._-]/', '', strtolower($base)) ?? '', '.');

        if ($base === '') {
            $base = 'client';
        }

        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = $base . '.' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function usersTableHasUsernameColumn(): bool
    {
        try {
            return Schema::hasColumn('users', 'username');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get client's rep from most recent shoot.
     */
    protected function getClientRep(int $clientId): ?int
    {
        return Shoot::where('client_id', $clientId)
            ->whereNotNull('rep_id')
            ->orderBy('created_at', 'desc')
            ->value('rep_id');
    }

}
