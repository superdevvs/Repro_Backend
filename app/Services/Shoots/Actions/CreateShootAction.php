<?php

namespace App\Services\Shoots\Actions;

use App\Http\Requests\StoreShootRequest;
use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\CreateShootResult;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateShootAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootWorkflowService $workflowService,
        protected ShootActivityLogger $activityLogger,
        protected InvoiceService $invoiceService,
        protected AutomationService $automationService,
        protected DropboxWorkflowService $dropboxService,
        protected MailService $mailService
    ) {
    }

    public function execute(StoreShootRequest $request, User $user): CreateShootResult
    {
        $validated = $request->validated();
        $this->support->ensureClientCanBookServices((int) $validated['client_id'], $validated['services']);

        $result = DB::transaction(function () use ($validated, $user, $request) {
            $taxCalculation = $this->support->buildTaxCalculation(
                $validated['services'],
                $validated['state'] ?? null,
                $validated['tax_region'] ?? null
            );
            $repId = $validated['rep_id'] ?? $this->support->getClientRep($validated['client_id']);
            $scheduledAt = !empty($validated['scheduled_at'])
                ? new \DateTime($validated['scheduled_at'])
                : null;

            $userRole = strtolower($user->role ?? '');
            $isClient = $userRole === 'client';
            $isClientSelfBooking = $validated['client_id'] == $user->id;
            $isClientRequestFlag = $request->input('is_client_request', false);

            Log::info('Shoot creation role check', [
                'user_id' => $user->id,
                'client_id' => $validated['client_id'],
                'user_role_raw' => $user->role,
                'user_role_normalized' => $userRole,
                'is_client' => $isClient,
                'is_admin_or_rep' => in_array($userRole, ['admin', 'superadmin', 'editing_manager', 'rep', 'salesrep']),
                'is_client_self_booking' => $isClientSelfBooking,
                'is_client_request_flag' => $isClientRequestFlag,
            ]);

            $treatAsClientRequest = $isClient || $isClientSelfBooking || $isClientRequestFlag;

            if ($treatAsClientRequest) {
                $initialStatus = Shoot::STATUS_REQUESTED;
                $workflowStatus = Shoot::STATUS_REQUESTED;
                $photographerId = $validated['photographer_id'] ?? null;
                Log::info('Client shoot - setting requested status', [
                    'status' => $initialStatus,
                    'photographer_id' => $photographerId,
                ]);
            } else {
                $initialStatus = 'hold_on';
                $workflowStatus = Shoot::STATUS_SCHEDULED;
                $photographerId = $validated['photographer_id'] ?? null;
            }

            if (!$treatAsClientRequest && $photographerId && $scheduledAt) {
                $carbonDate = \Carbon\Carbon::parse($scheduledAt);
                DB::table('shoots')
                    ->where('photographer_id', $photographerId)
                    ->whereDate('scheduled_at', $carbonDate->toDateString())
                    ->lockForUpdate()
                    ->get();

                $durationMinutes = $this->support->calculateShootDurationFromServices($validated['services']);
                $this->support->checkPhotographerAvailability($photographerId, $scheduledAt, $durationMinutes);
            }

            $propertyDetailsPayload = is_array($validated['property_details'] ?? null)
                ? $validated['property_details']
                : [];
            $autoPropertyTourLinks = array_filter([
                'property_mls' => $validated['mls_id']
                    ?? data_get($propertyDetailsPayload, 'mls_id')
                    ?? data_get($propertyDetailsPayload, 'mlsId'),
                'property_price' => data_get($propertyDetailsPayload, 'price'),
                'property_lot_size' => data_get($propertyDetailsPayload, 'lot_size')
                    ?? data_get($propertyDetailsPayload, 'lotSize'),
            ], static fn ($value) => $value !== null && $value !== '');
            $initialTourLinks = [];
            if (array_key_exists('tour_links', $validated) && is_array($validated['tour_links'])) {
                $initialTourLinks = $validated['tour_links'];
            }
            if (!empty($autoPropertyTourLinks)) {
                $initialTourLinks = array_merge($autoPropertyTourLinks, $initialTourLinks);
            }

            $shoot = Shoot::create([
                'client_id' => $validated['client_id'],
                'rep_id' => $repId,
                'photographer_id' => $photographerId,
                'service_id' => $validated['services'][0]['id'],
                'address' => $validated['address'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'zip' => $validated['zip'],
                'mls_id' => $validated['mls_id']
                    ?? data_get($propertyDetailsPayload, 'mls_id')
                    ?? data_get($propertyDetailsPayload, 'mlsId'),
                'listing_source' => $validated['listing_source'] ?? null,
                'property_details' => $validated['property_details'] ?? null,
                'tour_links' => !empty($initialTourLinks) ? $initialTourLinks : null,
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledAt ? $scheduledAt->format('Y-m-d') : null,
                'time' => $scheduledAt ? $scheduledAt->format('H:i') : ($validated['time'] ?? null),
                'status' => $initialStatus,
                'workflow_status' => $workflowStatus,
                'base_quote' => $taxCalculation['base_quote'],
                'tax_region' => $taxCalculation['tax_region'],
                'tax_percent' => $taxCalculation['tax_percent'],
                'tax_amount' => $taxCalculation['tax_amount'],
                'total_quote' => $taxCalculation['total_quote'],
                'bypass_paywall' => $validated['bypass_paywall'] ?? false,
                'payment_status' => 'unpaid',
                'created_by' => $user->name,
                'updated_by' => $user->name,
                'package_name' => $validated['package_name'] ?? null,
                'expected_final_count' => $validated['expected_final_count'] ?? null,
                'bracket_mode' => $validated['bracket_mode'] ?? null,
                'expected_raw_count' => $this->support->calculateExpectedRawCount(
                    $validated['expected_final_count'] ?? null,
                    $validated['bracket_mode'] ?? null
                ),
                'shoot_notes' => $validated['shoot_notes'] ?? null,
                'company_notes' => $validated['company_notes'] ?? null,
                'photographer_notes' => $validated['photographer_notes'] ?? null,
                'editor_notes' => $validated['editor_notes'] ?? null,
            ]);

            $this->support->attachServices($shoot, $validated['services']);
            $this->support->assignServicePhotographers($shoot, $request->input('service_photographers'));

            if ($scheduledAt && !$treatAsClientRequest) {
                try {
                    $this->invoiceService->generateForShoot($shoot);
                } catch (\Exception $e) {
                    Log::warning('Failed to auto-create invoice for shoot', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->support->createNotes($shoot, $validated, $user);

            if (!$treatAsClientRequest && $scheduledAt) {
                $this->workflowService->schedule($shoot, $scheduledAt, $user);
            }

            $this->activityLogger->log(
                $shoot,
                $treatAsClientRequest ? 'shoot_requested' : 'shoot_created',
                [
                    'by' => $user->name,
                    'status' => $initialStatus,
                    'scheduled_at' => $scheduledAt ? \Carbon\Carbon::instance($scheduledAt)->toIso8601String() : null,
                ],
                $user
            );

            return new CreateShootResult($shoot, $treatAsClientRequest, $scheduledAt);
        });

        $this->registerDeferredSideEffects($result);

        return $result;
    }

    protected function registerDeferredSideEffects(CreateShootResult $result): void
    {
        $shootId = $result->shoot->id;
        $treatAsClientRequest = $result->treatAsClientRequest;
        $scheduledAt = $result->scheduledAt;
        $automationService = $this->automationService;
        $dropboxService = $this->dropboxService;
        $mailService = $this->mailService;

        app()->terminating(function () use (
            $shootId,
            $treatAsClientRequest,
            $scheduledAt,
            $automationService,
            $dropboxService,
            $mailService
        ) {
            $shoot = Shoot::with(['client', 'photographer', 'rep', 'service', 'services'])->find($shootId);
            if (!$shoot) {
                return;
            }

            if ($treatAsClientRequest) {
                try {
                    $context = $automationService->buildShootContext($shoot);
                    if ($shoot->rep) {
                        $context['rep'] = $shoot->rep;
                    }
                    $automationService->handleEvent('SHOOT_REQUESTED', $context);
                } catch (\Exception $e) {
                    Log::error('Failed to trigger SHOOT_REQUESTED automation', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$treatAsClientRequest && $scheduledAt) {
                try {
                    $dropboxService->createShootFolders($shoot);
                } catch (\Exception $e) {
                    Log::error('Failed to create Dropbox folders for shoot', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$treatAsClientRequest) {
                try {
                    $context = $automationService->buildShootContext($shoot);
                    if ($shoot->rep) {
                        $context['rep'] = $shoot->rep;
                    }
                    $automationService->handleEvent('SHOOT_BOOKED', $context);
                } catch (\Exception $e) {
                    Log::error('Failed to trigger SHOOT_BOOKED automation', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$treatAsClientRequest && $scheduledAt) {
                try {
                    $shoot->loadMissing(['client', 'photographer', 'services']);
                    $client = $shoot->client;
                    if ($client && !$automationService->hasActiveTrigger('SHOOT_BOOKED')) {
                        $paymentLink = $mailService->generatePaymentLink($shoot);
                        $mailService->sendShootScheduledEmail($client, $shoot, $paymentLink);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to send shoot scheduled email during creation', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
