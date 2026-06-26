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
use App\Services\MailService;
use App\Services\ShootWorkflowService;
use App\Services\ShootActivityLogger;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\ShootMutationSupportService;
use App\Services\Users\DashboardOnboardingService;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\ExternalBooking\Data\ExternalBookingData;
use App\Services\ExternalBooking\ExternalBookingScheduleNormalizer;
use App\Services\ExternalBooking\ExternalBookingAutoMapper;
use App\Services\ExternalBooking\ExternalBookingWarningBuilder;
use App\Services\ExternalBooking\ExternalBookingNotificationService;
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
    protected ExternalBookingScheduleNormalizer $scheduleNormalizer;
    protected ExternalBookingAutoMapper $autoMapper;
    protected ExternalBookingWarningBuilder $warningBuilder;
    protected ExternalBookingNotificationService $notificationService;

    public function __construct(
        ShootTaxService $taxService,
        ShootActivityLogger $activityLogger,
        AutomationService $automationService,
        ShootMutationSupportService $shootSupport,
        ExternalBookingScheduleNormalizer $scheduleNormalizer,
        ExternalBookingAutoMapper $autoMapper,
        ExternalBookingWarningBuilder $warningBuilder,
        ExternalBookingNotificationService $notificationService
    ) {
        $this->taxService = $taxService;
        $this->activityLogger = $activityLogger;
        $this->automationService = $automationService;
        $this->shootSupport = $shootSupport;
        $this->scheduleNormalizer = $scheduleNormalizer;
        $this->autoMapper = $autoMapper;
        $this->warningBuilder = $warningBuilder;
        $this->notificationService = $notificationService;
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

        // Build the DTO and run the conservative mapping pipeline BEFORE the transaction.
        // These collaborators are pure (no DB writes), so they are safe to run up front.
        $data       = ExternalBookingData::fromRequest($request);
        $normalized = $this->scheduleNormalizer->normalize($data);
        $mapping    = $this->autoMapper->map($normalized);
        $warnings   = $this->warningBuilder->build($normalized, $mapping);

        try {
            $result = DB::transaction(function () use ($validated, $request, $data, $normalized, $mapping, $warnings) {
                $createAccount = $this->shouldCreateAccount($validated);

                // 1. Find or create client by email
                $client = $this->findOrCreateClient($validated, $createAccount);
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

                // 4. Resolve the shoot-level schedule from the mapping result. The mapper
                //    honors the no-fabricated-time rule (date without time => null time /
                //    scheduled_at, never a fabricated 00:00) (2.12, 2.14).
                $shootScheduledAt = $mapping->shootSchedule['scheduled_at']; // 'Y-m-d H:i:s' | null

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
                $isNoCharge = (float) $pricingCalculation['total_quote'] <= 0.01;
                $shoot = Shoot::create([
                    'client_id' => $client->id,
                    'rep_id' => $repId,
                    'photographer_id' => $mapping->legacyPhotographerId, // null unless case A (S=1,P=1)
                    'service_id' => $services[0]['id'], // Legacy support
                    'address' => $validated['address'],
                    'city' => $validated['city'],
                    'state' => $validated['state'],
                    'zip' => $validated['zip'],
                    'mls_id' => $validated['mls_id'] ?? null,
                    'property_details' => !empty($propertyDetails) ? $propertyDetails : null,
                    'scheduled_at' => $mapping->shootSchedule['scheduled_at'],
                    'scheduled_date' => $mapping->shootSchedule['scheduled_date'],
                    'time' => $mapping->shootSchedule['time'],
                    'alternate_scheduled_date' => $mapping->alternateSchedule['alternate_scheduled_date'],
                    'alternate_time' => $mapping->alternateSchedule['alternate_time'],
                    'alternate_scheduled_at' => $mapping->alternateSchedule['alternate_scheduled_at'],
                    'requested_photographers' => $normalized->requested_photographers,
                    'external_booking_payload' => $data->rawPayload,
                    'external_booking_warnings' => $warnings,
                    'external_booking_mapping_status' => $mapping->mappingStatus,
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
                    'bypass_paywall' => $isNoCharge,
                    'payment_status' => $isNoCharge ? 'paid' : 'unpaid',
                    'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
                    'product_status' => $isNoCharge ? Shoot::PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT : Shoot::PRODUCT_STATUS_HAS_PRODUCT,
                    'created_by' => "External ({$source})",
                    'updated_by' => "External ({$source})",
                    'shoot_notes' => $validated['notes'] ?? null,
                ]);

                // 8. Attach services with catalog prices plus per-service photographer /
                //    schedule assignments where the mapping was safe (null otherwise) (2.17).
                $this->shootSupport->attachServices($shoot, $this->buildServicesPayload($normalized, $mapping));

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
                        'scheduled_at' => $shootScheduledAt ? \Carbon\Carbon::parse($shootScheduledAt)->toIso8601String() : null,
                    ],
                    null // No authenticated user for external requests
                );

                if (!$createAccount) {
                    $shoot->ghostUsers()->syncWithoutDetaching([$client->id]);
                }

                return [
                    'shoot' => $shoot,
                    'client' => $client,
                    'is_new_client' => $createAccount && $client->wasRecentlyCreated,
                    'account_created' => $createAccount && $client->wasRecentlyCreated,
                    'account_setup_required' => $createAccount && $client->wasRecentlyCreated,
                    'is_guest_booking' => !$createAccount,
                ];
            });

            $shoot = $result['shoot'];

            if ($result['account_setup_required']) {
                $this->sendNewExternalClientAccountSetup($result['client'], $shoot);
            }

            ProcessExternalShootRequestedJob::dispatch($shoot->id)->afterCommit();

            // Raise the review notification AFTER the transaction commits. A notification
            // failure must never roll back the booking, so it is wrapped in try/catch (2.19).
            try {
                $this->notificationService->notifyIfNeeded($shoot, $mapping, $warnings);
            } catch (\Throwable $exception) {
                Log::warning('External booking review notification failed.', [
                    'shoot_id' => $shoot->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Shoot request submitted successfully. It will be reviewed by our team.',
                'data' => [
                    'shoot_id' => $shoot->id,
                    'status' => 'requested',
                    'client_id' => $result['client']->id,
                    'is_new_client' => $result['is_new_client'],
                    'account_created' => $result['account_created'],
                    'account_setup_required' => $result['account_setup_required'],
                    'is_guest_booking' => $result['is_guest_booking'],
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
     * Merge each resolved service with the auto-mapper's per-service assignment, producing
     * the array shape {@see ShootMutationSupportService::attachServices} already understands
     * (`id`, `quantity`, `photographer_id`, `scheduled_at`). The `photographer_id` and
     * `scheduled_at` keys are ALWAYS present so the pivot is set explicitly — to the mapped
     * value where safe, or `null` where the mapping was unsafe/ambiguous (2.17).
     *
     * @return array<int, array{id:int, quantity:?int, photographer_id:?int, scheduled_at:?string}>
     */
    protected function buildServicesPayload(
        \App\Services\ExternalBooking\NormalizedBooking $normalized,
        \App\Services\ExternalBooking\MappingResult $mapping
    ): array {
        $payload = [];

        foreach ($normalized->selected_services as $service) {
            $serviceId = (int) $service['id'];
            $assignment = $mapping->assignmentFor($serviceId);

            $payload[] = [
                'id' => $serviceId,
                'quantity' => $service['quantity'] ?? 1,
                'photographer_id' => $assignment['photographer_id'],
                'scheduled_at' => $assignment['scheduled_at'],
            ];
        }

        return $payload;
    }

    /**
     * Public endpoint for external sites to check if a client exists by email.
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
    protected function findOrCreateClient(array $data, bool $createAccount = true): User
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

        $metadata = app(DashboardOnboardingService::class)->applyEligibility([], 'client', 'external_booking');
        if (!$createAccount) {
            $metadata['guest_booking'] = true;
            $metadata['guest_booking_source'] = $data['source'] ?? 'external_website';
            $metadata['guest_booking_created_at'] = now()->toIso8601String();
            $metadata['dashboard_account_opted_out'] = true;
        }

        $attributes = [
            'name' => $data['client_name'],
            'username' => $this->generateUniqueUsername($data['client_name'], $email),
            'email' => $email,
            'phone' => $data['client_phone'] ?? null,
            'company_notes' => $data['client_company'] ?? null,
            'role' => 'client',
            'password' => Hash::make(Str::random(32)),
            'metadata' => $metadata,
        ];

        if (!$createAccount && $this->usersTableHasColumn('locked_at')) {
            $attributes['locked_at'] = now();
        }

        return User::create($attributes);
    }

    protected function shouldCreateAccount(array $data): bool
    {
        return filter_var($data['create_account'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    protected function sendNewExternalClientAccountSetup(User $client, Shoot $shoot): void
    {
        try {
            $mailService = app(MailService::class);
            $resetLink = $mailService->generateStoredPasswordResetLink($client);
            $verificationToken = app(ClientEmailVerificationLinkService::class)->issueVerificationToken($client, [
                'issued_context' => 'external_booking',
                'shoot_id' => $shoot->id,
            ]);
            $verificationLink = app(ClientEmailVerificationLinkService::class)->buildUrlForIssuedToken($client, $verificationToken);

            $mailService->sendAccountCreatedEmail($client, $resetLink, $verificationLink, null, 0, true);
            $mailService->sendClientEmailVerificationEmail($client, [
                'issued_context' => 'external_booking',
                'shoot_id' => $shoot->id,
                'verification_token' => $verificationToken,
                'verification_link' => $verificationLink,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('External booking client account setup email failed.', [
                'shoot_id' => $shoot->id,
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);
        }
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
        return $this->usersTableHasColumn('username');
    }

    protected function usersTableHasColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('users', $column);
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
