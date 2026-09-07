<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\Service;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingTools
{
    private ShootMediaStorageService $mediaStorageService;
    private MailService $mailService;
    private AutomationService $automationService;
    private ShootMutationSupportService $support;

    public function __construct()
    {
        $this->mediaStorageService = app(ShootMediaStorageService::class);
        $this->mailService = app(MailService::class);
        $this->automationService = app(AutomationService::class);
        $this->support = app(ShootMutationSupportService::class);
    }

    /**
     * Book a photography shoot
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context (user_id, etc.)
     * @return array Result of booking operation
     */
    public function bookShoot(array $params, array $context = []): array
    {
        try {
            $userId = $context['user_id'] ?? auth()->id();
            
            if (!$userId) {
                return [
                    'success' => false,
                    'error' => 'User not authenticated',
                ];
            }

            // Validate required fields
            $required = ['address', 'city', 'state', 'zip', 'services'];
            foreach ($required as $field) {
                if (empty($params[$field])) {
                    return [
                        'success' => false,
                        'error' => "Missing required field: {$field}",
                    ];
                }
            }

            $client = $this->support->ensureClientHasDeliverableEmail((int) $userId);

            // Get or default services
            $serviceIds = is_array($params['services']) ? $params['services'] : [$params['services']];
            $services = Service::whereIn('id', $serviceIds)->get();
            
            if ($services->isEmpty()) {
                return [
                    'success' => false,
                    'error' => 'No valid services found',
                ];
            }

            // Calculate pricing
            $baseQuote = $services->sum(function ($service) {
                return $service->price ?? 0;
            });
            $taxAmount = $baseQuote * 0.08; // 8% tax (adjust as needed)
            $totalQuote = $baseQuote + $taxAmount;

            // Prepare shoot data
            $shootData = [
                'client_id' => $userId,
                'photographer_id' => $params['photographer_id'] ?? null,
                'service_id' => $services->first()->id,
                'address' => $params['address'],
                'city' => $params['city'],
                'state' => $params['state'],
                'zip' => $params['zip'],
                'scheduled_date' => $params['date'] ?? null,
                'time' => $params['time'] ?? null,
                'base_quote' => $baseQuote,
                'tax_amount' => $taxAmount,
                'total_quote' => $totalQuote,
                'payment_status' => 'unpaid',
                'notes' => $params['notes'] ?? null,
                'shoot_notes' => $params['notes'] ?? null,
                'status' => (!empty($params['date']) && !empty($params['time'])) ? 'scheduled' : 'on_hold',
                'workflow_status' => Shoot::WORKFLOW_BOOKED,
                'created_by' => auth()->user()->name ?? 'Robbie',
            ];

            DB::beginTransaction();
            
            try {
                $shoot = Shoot::create($shootData);

                // Attach services
                $pivotData = $services->mapWithKeys(function ($service) {
                    return [
                        $service->id => [
                            'price' => $service->price ?? 0,
                            'quantity' => 1,
                        ],
                    ];
                })->toArray();
                $shoot->services()->sync($pivotData);

                // Create Dropbox folders if scheduled
                if ($shoot->status === 'scheduled') {
                    $this->mediaStorageService->createShootFolders($shoot);
                }

                $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
                $context = $this->automationService->buildShootContext($shoot);
                if ($shoot->rep) {
                    $context['rep'] = $shoot->rep;
                }
                $shootBookedDispatch = $this->automationService->handleEvent('SHOOT_BOOKED', $context);
                if ($shoot->scheduled_at) {
                    $context['scheduled_at'] = $shoot->scheduled_at?->toISOString();
                    $this->automationService->handleEvent('SHOOT_SCHEDULED', $context);
                }

                if ($shoot->status === 'scheduled') {
                    $shouldUseFallback = $this->automationService->shouldUseFallback('SHOOT_BOOKED', $shootBookedDispatch) !== false;
                    Log::info('AI shoot booking fallback decision evaluated', [
                        'shoot_id' => $shoot->id,
                        'trigger_type' => 'SHOOT_BOOKED',
                        'fallback_used' => $shouldUseFallback,
                        'dispatch' => $this->formatDispatchSummaryForLog($shootBookedDispatch),
                    ]);

                    $clientEmailSent = (bool) ($shootBookedDispatch['client_email_sent'] ?? false);
                    $photographerEmailSent = (bool) ($shootBookedDispatch['photographer_email_sent'] ?? false);

                    if ($client && ($shouldUseFallback || !$clientEmailSent) && !$clientEmailSent) {
                        $paymentLink = $this->mailService->generatePaymentLink($shoot);
                        $this->mailService->sendShootScheduledEmail($client, $shoot, $paymentLink, false);
                    }

                    if ($shouldUseFallback || !$photographerEmailSent) {
                        $this->mailService->sendAssignedPhotographerShootScheduledEmails($shoot);
                    }
                }

                DB::commit();

                return [
                    'success' => true,
                    'shoot_id' => $shoot->id,
                    'status' => $shoot->status,
                    'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                    'time' => $shoot->time,
                    'total_quote' => $totalQuote,
                    'services' => $services->pluck('name')->toArray(),
                    'message' => $shoot->status === 'scheduled' 
                        ? "Shoot booked successfully for {$shoot->scheduled_date?->format('M d, Y')} at {$shoot->time}"
                        : "Shoot created. Please schedule a date and time to complete booking.",
                ];
            } catch (ValidationException $e) {
                DB::rollBack();
                Log::warning('AI booking rejected during shoot creation.', [
                    'user_id' => $userId,
                    'errors' => $e->errors(),
                    'params' => $params,
                ]);

                return [
                    'success' => false,
                    'error' => $e->errors()['client_id'][0] ?? 'Selected client must have a primary email before booking a shoot.',
                    'errors' => $e->errors(),
                    'status_code' => 422,
                ];
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to create shoot via AI', [
                    'error' => $e->getMessage(),
                    'params' => $params,
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Failed to create shoot: ' . $e->getMessage(),
                ];
            }
        } catch (ValidationException $e) {
            Log::warning('BookingTools rejected request.', [
                'user_id' => $context['user_id'] ?? auth()->id(),
                'errors' => $e->errors(),
                'params' => $params,
            ]);

            return [
                'success' => false,
                'error' => $e->errors()['client_id'][0] ?? 'Selected client must have a primary email before booking a shoot.',
                'errors' => $e->errors(),
                'status_code' => 422,
            ];
        } catch (\Exception $e) {
            Log::error('BookingTools error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function formatDispatchSummaryForLog(?array $dispatch): array
    {
        if (!is_array($dispatch)) {
            return [
                'present' => false,
            ];
        }

        return [
            'present' => true,
            'active_rule_count' => $dispatch['active_rule_count'] ?? null,
            'handled' => $dispatch['handled'] ?? null,
            'failed_run_count' => $dispatch['failed_run_count'] ?? null,
            'error_count' => count($dispatch['errors'] ?? []),
            'client_email_sent' => $dispatch['client_email_sent'] ?? null,
            'photographer_email_sent' => $dispatch['photographer_email_sent'] ?? null,
        ];
    }
}
