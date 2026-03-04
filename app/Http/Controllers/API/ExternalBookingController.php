<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExternalBookingRequest;
use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use App\Models\Service;
use App\Models\User;
use App\Services\ShootTaxService;
use App\Services\ShootWorkflowService;
use App\Services\ShootActivityLogger;
use App\Services\Messaging\AutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExternalBookingController extends Controller
{
    protected ShootTaxService $taxService;
    protected ShootActivityLogger $activityLogger;
    protected AutomationService $automationService;

    public function __construct(
        ShootTaxService $taxService,
        ShootActivityLogger $activityLogger,
        AutomationService $automationService
    ) {
        $this->taxService = $taxService;
        $this->activityLogger = $activityLogger;
        $this->automationService = $automationService;
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

                // 2. Calculate pricing from service catalog
                $services = $validated['services'];
                $baseQuote = $this->calculateBaseQuote($services);

                // 3. Determine tax
                $taxRegion = $this->taxService->determineTaxRegion($validated['state']);
                $taxCalculation = $this->taxService->calculateTotal($baseQuote, $taxRegion);

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
                    'base_quote' => $taxCalculation['base_quote'],
                    'tax_region' => $taxCalculation['tax_region'],
                    'tax_percent' => $taxCalculation['tax_percent'],
                    'tax_amount' => $taxCalculation['tax_amount'],
                    'total_quote' => $taxCalculation['total_quote'],
                    'bypass_paywall' => false,
                    'payment_status' => 'unpaid',
                    'created_by' => "External ({$source})",
                    'updated_by' => "External ({$source})",
                    'shoot_notes' => $validated['notes'] ?? null,
                ]);

                // 8. Attach services with catalog prices
                $this->attachServices($shoot, $services);

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

            // Post-transaction: trigger automations (non-blocking)
            $shootId = $shoot->id;
            $automationService = $this->automationService;

            app()->terminating(function () use ($shootId, $automationService) {
                $shoot = Shoot::with(['client', 'photographer', 'rep', 'service', 'services'])->find($shootId);
                if (!$shoot) return;

                try {
                    $context = $automationService->buildShootContext($shoot);
                    if ($shoot->rep) {
                        $context['rep'] = $shoot->rep;
                    }
                    $automationService->handleEvent('SHOOT_REQUESTED', $context);
                } catch (\Exception $e) {
                    Log::error('Failed to trigger SHOOT_REQUESTED automation for external booking', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

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
            if ($updated) {
                $client->save();
            }
            return $client;
        }

        // Create new client account with a random password (they'll reset it)
        return User::create([
            'name' => $data['client_name'],
            'email' => $email,
            'phone' => $data['client_phone'] ?? null,
            'company_notes' => $data['client_company'] ?? null,
            'role' => 'client',
            'password' => Hash::make(Str::random(32)),
        ]);
    }

    /**
     * Calculate base quote from service catalog prices.
     */
    protected function calculateBaseQuote(array $services): float
    {
        $total = 0;
        $serviceIds = collect($services)->pluck('id');
        $serviceModels = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        foreach ($services as $service) {
            $serviceModel = $serviceModels->get($service['id']);
            $price = $serviceModel?->price ?? 0;
            $quantity = $service['quantity'] ?? 1;
            $total += $price * $quantity;
        }

        return round($total, 2);
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

    /**
     * Attach services to shoot pivot table.
     */
    protected function attachServices(Shoot $shoot, array $services): void
    {
        $serviceIds = collect($services)->pluck('id');
        $serviceModels = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        $pivotData = collect($services)->mapWithKeys(function ($service) use ($serviceModels) {
            $serviceModel = $serviceModels->get($service['id']);
            return [
                $service['id'] => [
                    'price' => $serviceModel?->price ?? 0,
                    'quantity' => $service['quantity'] ?? 1,
                    'photographer_pay' => null,
                ],
            ];
        })->toArray();

        $shoot->services()->sync($pivotData);
    }
}
