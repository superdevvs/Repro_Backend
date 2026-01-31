<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\MailService;
use App\Models\User;
use App\Models\Shoot;
use App\Models\Payment;

class TestMailController extends Controller
{
    private MailService $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * Test account created email
     */
    public function testAccountCreated(Request $request): JsonResponse
    {
        try {
            $user = User::first();
            if (!$user) {
                return response()->json(['error' => 'No users found in database'], 404);
            }

            $resetLink = $this->mailService->generatePasswordResetLink($user);
            $result = $this->mailService->sendAccountCreatedEmail($user, $resetLink);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Account created email sent successfully' : 'Failed to send email',
                'user' => $user->email,
                'reset_link' => $resetLink
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to send test email',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test shoot scheduled email
     */
    public function testShootScheduled(Request $request): JsonResponse
    {
        try {
            $user = User::first();
            $shoot = Shoot::first();
            
            if (!$user || !$shoot) {
                return response()->json(['error' => 'No user or shoot found in database'], 404);
            }

            $paymentLink = $this->mailService->generatePaymentLink($shoot);
            $result = $this->mailService->sendShootScheduledEmail($user, $shoot, $paymentLink);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Shoot scheduled email sent successfully' : 'Failed to send email',
                'user' => $user->email,
                'shoot' => $shoot->location,
                'payment_link' => $paymentLink
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to send test email',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test shoot ready email
     */
    public function testShootReady(Request $request): JsonResponse
    {
        try {
            $user = User::first();
            $shoot = Shoot::first();
            
            if (!$user || !$shoot) {
                return response()->json(['error' => 'No user or shoot found in database'], 404);
            }

            $result = $this->mailService->sendShootReadyEmail($user, $shoot);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Shoot ready email sent successfully' : 'Failed to send email',
                'user' => $user->email,
                'shoot' => $shoot->location
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to send test email',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test payment confirmation email
     */
    public function testPaymentConfirmation(Request $request): JsonResponse
    {
        try {
            $user = User::first();
            $shoot = Shoot::first();
            $payment = Payment::first();
            
            if (!$user || !$shoot) {
                return response()->json(['error' => 'No user or shoot found in database'], 404);
            }

            // Create a test payment if none exists
            if (!$payment) {
                $payment = Payment::create([
                    'shoot_id' => $shoot->id,
                    'amount' => $shoot->grand_total ?? 100.00,
                    'currency' => 'USD',
                    'status' => Payment::STATUS_COMPLETED,
                    'square_payment_id' => 'test_payment_' . time(),
                    'square_order_id' => 'test_order_' . time(),
                    'processed_at' => now()
                ]);
            }

            $result = $this->mailService->sendPaymentConfirmationEmail($user, $shoot, $payment);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Payment confirmation email sent successfully' : 'Failed to send email',
                'user' => $user->email,
                'shoot' => $shoot->location,
                'payment_amount' => $payment->amount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to send test email',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test all emails
     */
    public function testAllEmails(Request $request): JsonResponse
    {
        $results = [];

        // Test account created
        try {
            $accountResult = $this->testAccountCreated($request);
            $results['account_created'] = json_decode($accountResult->getContent(), true);
        } catch (\Exception $e) {
            $results['account_created'] = ['error' => $e->getMessage()];
        }

        // Test shoot scheduled
        try {
            $scheduledResult = $this->testShootScheduled($request);
            $results['shoot_scheduled'] = json_decode($scheduledResult->getContent(), true);
        } catch (\Exception $e) {
            $results['shoot_scheduled'] = ['error' => $e->getMessage()];
        }

        // Test shoot ready
        try {
            $readyResult = $this->testShootReady($request);
            $results['shoot_ready'] = json_decode($readyResult->getContent(), true);
        } catch (\Exception $e) {
            $results['shoot_ready'] = ['error' => $e->getMessage()];
        }

        // Test payment confirmation
        try {
            $paymentResult = $this->testPaymentConfirmation($request);
            $results['payment_confirmation'] = json_decode($paymentResult->getContent(), true);
        } catch (\Exception $e) {
            $results['payment_confirmation'] = ['error' => $e->getMessage()];
        }

        return response()->json([
            'message' => 'All email tests completed',
            'results' => $results
        ]);
    }

    /**
     * Get mail configuration info
     */
    public function getMailConfig(): JsonResponse
    {
        return response()->json([
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.cakemail.host'),
            'port' => config('mail.mailers.cakemail.port'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'log_channel' => config('logging.default')
        ]);
    }
}