<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageChannel;
use App\Models\SmsNumber;
use App\Services\Messaging\Providers\MightyCallSmsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessagingSettingsController extends Controller
{
    public function emailSettings(): JsonResponse
    {
        $channels = MessageChannel::ofType('EMAIL')->orderBy('display_name')->get();

        if ($channels->isEmpty()) {
            $defaultName = config('mail.from.name', 'Cakemail');
            $defaultEmail = config('mail.from.address', 'noreply@reprophotos.com');

            MessageChannel::create([
                'type' => 'EMAIL',
                'provider' => 'CAKEMAIL',
                'display_name' => $defaultName,
                'from_email' => $defaultEmail,
                'is_default' => true,
                'owner_scope' => 'GLOBAL',
            ]);

            $channels = MessageChannel::ofType('EMAIL')->orderBy('display_name')->get();
        }

        return response()->json([
            'channels' => $channels,
        ]);
    }

    public function saveEmailSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channels' => ['required', 'array'],
            'channels.*.id' => ['nullable', 'integer', 'exists:message_channels,id'],
            'channels.*.display_name' => ['required', 'string'],
            'channels.*.from_email' => ['nullable', 'email'],
            'channels.*.provider' => ['required', 'in:CAKEMAIL'],
            'channels.*.is_default' => ['boolean'],
            'channels.*.config_json' => ['nullable', 'array'],
            'channels.*.label' => ['nullable', 'string'],
        ]);

        foreach ($data['channels'] as $channelData) {
            $channelPayload = array_merge(
                $channelData,
                [
                    'label' => $channelData['label'] ?? $channelData['display_name'],
                    'owner_scope' => $channelData['owner_scope'] ?? 'GLOBAL',
                ]
            );

            if (!empty($channelData['id'])) {
                $channel = MessageChannel::find($channelData['id']);
                $channel?->update($channelPayload);
            } else {
                MessageChannel::create(array_merge($channelPayload, [
                    'type' => 'EMAIL',
                ]));
            }
        }

        return response()->json(['status' => 'saved']);
    }

    public function smsSettings(): JsonResponse
    {
        $numbers = SmsNumber::orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();

        if ($numbers->isEmpty()) {
            $defaultNumber = config('services.mightycall.default_number');
            $defaultLabel = config('services.mightycall.default_label', 'MightyCall');
            $defaultUserKey = config('services.mightycall.default_user_key');

            if (!empty($defaultNumber)) {
                SmsNumber::create([
                    'phone_number' => $defaultNumber,
                    'label' => $defaultLabel,
                    'mighty_call_key' => $defaultUserKey,
                    'owner_type' => 'GLOBAL',
                    'is_default' => true,
                ]);

                $numbers = SmsNumber::orderByDesc('is_default')
                    ->orderBy('created_at')
                    ->get();
            }
        }

        \Log::info('SMS Settings requested', [
            'count' => $numbers->count(),
            'numbers' => $numbers->map(fn($n) => [
                'id' => $n->id,
                'phone' => $n->phone_number,
                'has_key' => !empty($n->mighty_call_key),
            ]),
        ]);

        return response()->json([
            'numbers' => $numbers,
        ]);
    }

    public function saveSmsSettings(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'numbers' => ['required', 'array'],
                'numbers.*.id' => ['nullable', 'integer', 'exists:sms_numbers,id'],
                'numbers.*.phone_number' => ['required', 'string'],
                'numbers.*.label' => ['nullable', 'string'],
                'numbers.*.mighty_call_key' => ['nullable', 'string'],
                'numbers.*.is_default' => ['boolean'],
            ]);

            // If any number is being set as default, unset all other defaults first
            $hasNewDefault = collect($data['numbers'])->contains(function ($number) {
                return !empty($number['is_default']);
            });

            if ($hasNewDefault) {
                SmsNumber::query()->update(['is_default' => false]);
            }

            foreach ($data['numbers'] as $numberData) {
                if (!empty($numberData['id'])) {
                    $number = SmsNumber::find($numberData['id']);
                    if ($number) {
                        $number->update($numberData);
                    }
                } else {
                    SmsNumber::create($numberData);
                }
            }

            // Return the updated list of numbers
            $numbers = SmsNumber::orderByDesc('is_default')->get();

            return response()->json([
                'status' => 'saved',
                'numbers' => $numbers,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to save SMS settings: ' . $e->getMessage(), [
                'exception' => $e,
                'request_data' => $request->all(),
            ]);
            return response()->json([
                'error' => 'Failed to save SMS settings',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function createEmailChannel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:EMAIL'],
            'provider' => ['required', 'in:CAKEMAIL'],
            'display_name' => ['required', 'string'],
            'from_email' => ['required', 'email'],
            'reply_to_email' => ['nullable', 'email'],
            'is_default' => ['boolean'],
            'owner_scope' => ['required', 'in:GLOBAL,ACCOUNT,USER'],
            'owner_id' => ['nullable', 'integer'],
            'config_json' => ['nullable', 'array'],
        ]);

        $channel = MessageChannel::create($data);

        return response()->json($channel, 201);
    }

    public function updateEmailChannel(Request $request, MessageChannel $channel): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['string'],
            'from_email' => ['email'],
            'reply_to_email' => ['nullable', 'email'],
            'is_default' => ['boolean'],
            'config_json' => ['nullable', 'array'],
        ]);

        $channel->update($data);

        return response()->json($channel->fresh());
    }

    public function deleteEmailChannel(MessageChannel $channel): JsonResponse
    {
        // Check if channel is used by any automation
        $automationCount = \App\Models\AutomationRule::where('channel_id', $channel->id)->count();
        if ($automationCount > 0) {
            return response()->json([
                'error' => "Channel is used by {$automationCount} automation(s)",
            ], 400);
        }

        $channel->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function testEmailChannel(Request $request, MessageChannel $channel): JsonResponse
    {
        $data = $request->validate([
            'test_email' => ['required', 'email'],
        ]);

        $messagingService = app(\App\Services\Messaging\MessagingService::class);
        
        try {
            $messagingService->sendEmail([
                'to' => $data['test_email'],
                'subject' => 'Test Email from ' . $channel->display_name,
                'body_html' => '<p>This is a test email to verify your email channel configuration.</p>',
                'body_text' => 'This is a test email to verify your email channel configuration.',
                'channel_id' => $channel->id,
                'user_id' => $request->user()->id,
                'send_source' => 'MANUAL',
            ]);

            return response()->json(['status' => 'sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Test MightyCall API connection
     */
    public function testSmsConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms_number_id' => ['nullable', 'exists:sms_numbers,id'],
        ]);

        $apiKey = config('services.mightycall.api_key');

        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'error' => 'MightyCall API key not configured in environment. Add MIGHTYCALL_API_KEY to .env file.',
            ], 400);
        }

        $smsNumber = !empty($data['sms_number_id'])
            ? SmsNumber::find($data['sms_number_id'])
            : SmsNumber::where('is_default', true)->first();

        if (!$smsNumber) {
            return response()->json([
                'success' => false,
                'error' => 'No SMS number configured. Please add a number first.',
            ], 400);
        }

        if (empty($smsNumber->mighty_call_key)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected SMS number does not have a MightyCall user key configured.',
            ], 400);
        }

        $provider = app(MightyCallSmsProvider::class);
        $result = $provider->testConnection($apiKey, $smsNumber->mighty_call_key);
        
        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Test sending SMS to a specific number
     */
    public function testSmsSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'message' => ['required', 'string', 'max:160'],
            'sms_number_id' => ['nullable', 'exists:sms_numbers,id'],
        ]);

        try {
            $smsNumber = !empty($data['sms_number_id']) 
                ? SmsNumber::find($data['sms_number_id'])
                : SmsNumber::where('is_default', true)->first();

            if (!$smsNumber) {
                return response()->json([
                    'success' => false,
                    'error' => 'No SMS number configured. Please add a number first.',
                ], 400);
            }

            $provider = app(MightyCallSmsProvider::class);
            $messageId = $provider->send($smsNumber, [
                'to' => $data['to'],
                'text' => $data['message'],
            ]);

            return response()->json([
                'success' => true,
                'message_id' => $messageId,
                'from' => $smsNumber->phone_number,
                'to' => $data['to'],
            ]);
        } catch (\Exception $e) {
            \Log::error('Test SMS send failed', [
                'error' => $e->getMessage(),
                'to' => $data['to'],
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an SMS number
     */
    public function deleteSmsNumber(SmsNumber $smsNumber): JsonResponse
    {
        $smsNumber->delete();
        
        return response()->json(['status' => 'deleted']);
    }

    /**
     * Sync messages from MightyCall
     */
    public function syncSmsMessages(Request $request): JsonResponse
    {
        $hours = $request->input('hours', 24);
        
        try {
            \Artisan::call('mightycall:sync-messages', [
                '--hours' => $hours,
            ]);
            
            $output = \Artisan::output();
            
            return response()->json([
                'success' => true,
                'message' => 'Sync completed',
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

