<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageChannel;
use App\Models\Setting;
use App\Models\SmsNumber;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            $defaultNumber = config('services.telnyx.from_number');
            $defaultLabel = config('services.telnyx.default_label', 'Telnyx SMS');
            $defaultPhoneNumberId = config('services.telnyx.phone_number_id');
            $defaultMessagingProfileId = config('services.telnyx.messaging_profile_id');

            if (!empty($defaultNumber)) {
                SmsNumber::create([
                    'provider' => 'TELNYX',
                    'phone_number' => $defaultNumber,
                    'label' => $defaultLabel,
                    'telnyx_phone_number_id' => $defaultPhoneNumberId,
                    'messaging_profile_id' => $defaultMessagingProfileId,
                    'owner_type' => 'GLOBAL',
                    'is_default' => true,
                ]);

                $numbers = SmsNumber::orderByDesc('is_default')
                    ->orderBy('created_at')
                    ->get();
            }
        }

        Log::info('SMS Settings requested', [
            'count' => $numbers->count(),
            'numbers' => $numbers->map(fn ($n) => [
                'id' => $n->id,
                'phone' => $n->phone_number,
                'provider' => $n->provider,
                'has_phone_number_id' => !empty($n->telnyx_phone_number_id),
                'has_messaging_profile_id' => !empty($n->messaging_profile_id),
            ]),
        ]);

        return response()->json([
            'numbers' => $numbers,
            'ai' => array_merge([
                'enabled' => (bool) config('services.telnyx.ai_sms_enabled'),
                'takeover_pause_minutes' => (int) config('services.telnyx.ai_takeover_pause_minutes'),
                'idle_ttl_minutes' => (int) config('services.telnyx.ai_session_idle_ttl_minutes'),
                'pending_action_ttl_minutes' => (int) config('services.telnyx.ai_pending_action_ttl_minutes'),
                'max_segments' => (int) config('services.telnyx.ai_max_segments'),
                'max_replies_per_hour' => (int) config('services.telnyx.ai_max_replies_per_hour'),
                'verification_ttl_minutes' => (int) config('services.telnyx.ai_verification_ttl_minutes'),
                'static_replies' => config('services.telnyx.ai_static_replies', []),
                'allowed_tools' => [
                    'get_shoot_details',
                    'list_shoots',
                    'get_payment_status',
                    'get_availability',
                    'get_property',
                    'get_listing',
                    'get_editing_types',
                ],
            ], $this->storedAiSmsSettings()),
        ]);
    }

    public function saveSmsSettings(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'numbers' => ['required', 'array', 'max:1'],
                'numbers.*.id' => ['nullable', 'integer', 'exists:sms_numbers,id'],
                'numbers.*.phone_number' => ['required', 'string'],
                'numbers.*.label' => ['nullable', 'string'],
                'numbers.*.provider' => ['nullable', 'in:TELNYX'],
                'numbers.*.telnyx_phone_number_id' => ['nullable', 'string'],
                'numbers.*.messaging_profile_id' => ['nullable', 'string'],
                'numbers.*.is_default' => ['boolean'],
                'numbers.*.sms_ai_enabled' => ['nullable', 'boolean'],
                'ai' => ['nullable', 'array'],
                'ai.static_replies' => ['nullable', 'array'],
                'ai.static_replies.stop' => ['nullable', 'string', 'max:320'],
                'ai.static_replies.start' => ['nullable', 'string', 'max:320'],
                'ai.static_replies.help' => ['nullable', 'string', 'max:320'],
                'ai.allowed_tools' => ['nullable', 'array'],
                'ai.allowed_tools.*' => ['string', 'max:80'],
            ]);

            $submittedIds = collect($data['numbers'])
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($submittedIds === []) {
                SmsNumber::query()->delete();
            } else {
                SmsNumber::query()->whereNotIn('id', $submittedIds)->delete();
            }

            SmsNumber::query()->update(['is_default' => false]);

            foreach ($data['numbers'] as $index => $numberData) {
                $payload = [
                    'provider' => 'TELNYX',
                    'phone_number' => $numberData['phone_number'],
                    'label' => $numberData['label'] ?? config('services.telnyx.default_label', 'Telnyx SMS'),
                    'telnyx_phone_number_id' => $numberData['telnyx_phone_number_id'] ?? config('services.telnyx.phone_number_id'),
                    'messaging_profile_id' => $numberData['messaging_profile_id'] ?? config('services.telnyx.messaging_profile_id'),
                    'owner_type' => 'GLOBAL',
                    'is_default' => $numberData['is_default'] ?? $index === 0,
                    'sms_ai_enabled' => array_key_exists('sms_ai_enabled', $numberData)
                        ? $numberData['sms_ai_enabled']
                        : null,
                ];

                if (!empty($numberData['id'])) {
                    $number = SmsNumber::find($numberData['id']);
                    $number?->update($payload);
                } else {
                    SmsNumber::create($payload);
                }
            }

            $numbers = SmsNumber::orderByDesc('is_default')->get();

            return response()->json([
                'status' => 'saved',
                'numbers' => SmsNumber::orderByDesc('is_default')->orderBy('created_at')->get(),
                'ai' => $this->saveAiSmsSettings($data['ai'] ?? []),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => \App\Services\ApiErrorResponder::publicMessage($e),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'error' => 'Failed to save SMS settings',
                'message' => \App\Services\ApiErrorResponder::publicMessage($e),
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
            return response()->json(['error' => \App\Services\ApiErrorResponder::publicMessage($e)], 500);
        }
    }

    public function testSmsConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms_number_id' => ['nullable', 'exists:sms_numbers,id'],
        ]);

        $smsNumber = !empty($data['sms_number_id'])
            ? SmsNumber::find($data['sms_number_id'])
            : SmsNumber::where('is_default', true)->first();

        $provider = app(TelnyxSmsProvider::class);
        $result = $provider->testConnection($smsNumber);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    public function testSmsSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'message' => ['required', 'string', 'max:1200'],
            'sms_number_id' => ['nullable', 'exists:sms_numbers,id'],
        ]);

        try {
            $smsNumber = !empty($data['sms_number_id'])
                ? SmsNumber::find($data['sms_number_id'])
                : SmsNumber::where('is_default', true)->first();

            if (!$smsNumber) {
                return response()->json([
                    'success' => false,
                    'error' => 'No SMS sender configured. Please add your Telnyx number first.',
                ], 400);
            }

            $provider = app(TelnyxSmsProvider::class);
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
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'success' => false,
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function deleteSmsNumber(SmsNumber $smsNumber): JsonResponse
    {
        $smsNumber->delete();

        return response()->json(['status' => 'deleted']);
    }

    private function storedAiSmsSettings(): array
    {
        $setting = Setting::query()->where('key', 'messaging.telnyx_ai_sms')->first();
        if (!$setting) {
            return [];
        }

        $decoded = json_decode((string) $setting->value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function saveAiSmsSettings(array $ai): array
    {
        $current = $this->storedAiSmsSettings();
        $next = array_merge($current, array_filter([
            'static_replies' => $ai['static_replies'] ?? null,
            'allowed_tools' => $ai['allowed_tools'] ?? null,
        ], fn ($value) => $value !== null));

        Setting::query()->updateOrCreate(
            ['key' => 'messaging.telnyx_ai_sms'],
            [
                'value' => json_encode($next),
                'type' => 'json',
                'description' => 'Telnyx AI SMS Agent editable settings',
            ]
        );

        return array_merge([
            'enabled' => (bool) config('services.telnyx.ai_sms_enabled'),
            'takeover_pause_minutes' => (int) config('services.telnyx.ai_takeover_pause_minutes'),
            'idle_ttl_minutes' => (int) config('services.telnyx.ai_session_idle_ttl_minutes'),
            'pending_action_ttl_minutes' => (int) config('services.telnyx.ai_pending_action_ttl_minutes'),
            'max_segments' => (int) config('services.telnyx.ai_max_segments'),
            'max_replies_per_hour' => (int) config('services.telnyx.ai_max_replies_per_hour'),
            'verification_ttl_minutes' => (int) config('services.telnyx.ai_verification_ttl_minutes'),
            'static_replies' => config('services.telnyx.ai_static_replies', []),
            'allowed_tools' => [],
        ], $next);
    }
}
