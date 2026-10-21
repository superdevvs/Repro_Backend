<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\SmsNumber;
use App\Models\User;
use App\Models\VoiceLinePolicy;
use App\Services\Voice\VoiceLinePolicyService;
use App\Services\Voice\VoiceGreetingService;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoiceNumberController extends Controller
{
    private function present(SmsNumber $number): array
    {
        $lines = app(VoiceLinePolicyService::class);

        return array_merge($number->only(['id', 'phone_number', 'label', 'is_default', 'voice_ai_enabled', 'voice_assistant_id_override', 'sms_ai_enabled']),
            ['phone_number' => app(\App\Services\Messaging\AiSms\SmsContextResolverService::class)->normalize($number->phone_number),
                'line_policy' => $lines->present($lines->forNumber($number->phone_number)),
                'can_call_from' => $lines->forNumber($number->phone_number)?->mode !== 'sms_only'
                    && $lines->canAccess(request()->user(), new \App\Models\VoiceCall(['direction' => 'OUTBOUND', 'from_phone' => $number->phone_number]))]);
    }

    public function staff(): JsonResponse
    {
        return response()->json(['staff' => User::whereIn('role', ['superadmin', 'admin', 'editing_manager'])->get()
            ->filter(fn ($u) => $u->isAccountEligibleForAuthentication())->map(fn ($u) => $u->only(['id', 'name', 'role']))->values()]);
    }
    public function index(VoiceNumberSettingsResolver $numbers): JsonResponse
    {
        return response()->json([
            'numbers' => $numbers->canonicalNumbers()->map(fn (SmsNumber $number) => $this->present($number)),
        ]);
    }

    public function update(Request $request, SmsNumber $smsNumber, VoiceNumberSettingsResolver $numbers): JsonResponse
    {
        $data = $request->validate([
            'voice_ai_enabled' => ['sometimes', 'nullable', 'boolean'],
            'voice_assistant_id_override' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sms_ai_enabled' => ['sometimes', 'nullable', 'boolean'],
            'line_policy' => ['sometimes', 'array:mode,recipient_role,recipient_roles,recipient_ids,timezone,weekly_hours'],
            'line_policy.mode' => ['required_with:line_policy', 'in:staff_voicemail,sms_only,robbie'],
            'line_policy.recipient_role' => ['nullable', 'in:superadmin,editing_manager'],
            'line_policy.recipient_roles' => ['sometimes', 'array', 'max:2'],
            'line_policy.recipient_roles.*' => ['string', 'distinct', 'in:superadmin,editing_manager'],
            'line_policy.recipient_ids' => ['sometimes', 'array'],
            'line_policy.recipient_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'line_policy.timezone' => ['required_with:line_policy', 'timezone:all'],
            'line_policy.weekly_hours' => ['present_with:line_policy', 'array:mon,tue,wed,thu,fri,sat,sun'],
            'line_policy.weekly_hours.*' => ['array', 'max:4'],
            'line_policy.weekly_hours.*.*' => ['array:start,end'],
            'line_policy.weekly_hours.*.*.start' => ['required', 'date_format:H:i'],
            'line_policy.weekly_hours.*.*.end' => ['required', 'date_format:H:i'],
        ]);

        abort_unless(strtoupper(trim((string) $smsNumber->provider)) === 'TELNYX', 422, 'This is not a Telnyx voice number.');
        $phone = $numbers->normalize($smsNumber->phone_number);
        $policyData = $data['line_policy'] ?? null;
        unset($data['line_policy']);
        if ($policyData) {
            foreach ($policyData['weekly_hours'] as $slots) {
                foreach ($slots as $slot) {
                    abort_unless($slot['start'] < $slot['end'], 422, 'Opening windows must end after they start.');
                }
            }
            $policyData['recipient_roles'] = $policyData['recipient_roles'] ?? array_values(array_filter([$policyData['recipient_role'] ?? null]));
            $policyData['recipient_role'] = $policyData['recipient_roles'][0] ?? null;
            $policyData['recipient_ids'] = $policyData['recipient_ids'] ?? [];
            abort_if($phone === '+18888041663' && $policyData['mode'] !== 'sms_only', 422, 'Robbie remains SMS-only.');
            if ($policyData['mode'] === 'robbie') {
                $policyData['recipient_ids'] = [];
                $policyData['recipient_role'] = null;
                $policyData['recipient_roles'] = [];
            }
            $data['voice_ai_enabled'] = $policyData['mode'] === 'robbie';
        }
        abort_if(($data['voice_ai_enabled'] ?? false) && (($policyData['mode'] ?? app(VoiceLinePolicyService::class)->forNumber($phone)?->mode) !== 'robbie') && app(VoiceLinePolicyService::class)->forNumber($phone), 422, 'This line uses staff voicemail or SMS-only routing.');
        $canonical = LockedWrite::run(fn () => DB::transaction(function () use ($smsNumber, $numbers, $data, $phone, $policyData): SmsNumber {
            $duplicates = $numbers->matching($smsNumber->phone_number, $smsNumber->provider);
            abort_if($duplicates->isEmpty(), 422, 'This number has no valid phone address.');
            // These are physical-line switches, even when historical message
            // rows reference different owner records. Keep all identities/FKs.
            SmsNumber::query()->whereIn('id', $duplicates->modelKeys())->update($data);
            if ($policyData) {
                VoiceLinePolicy::updateOrCreate(['phone_number' => $phone], $policyData);
            }

            return $duplicates->first()->fresh();
        }), 'voice.number.update');

        return response()->json($this->present($canonical));
    }

    public function greeting(Request $request, SmsNumber $smsNumber): JsonResponse
    {
        $request->validate(['kind' => ['required', 'in:office,closed'], 'audio' => ['required', 'file', 'max:684']]);
        abort_unless(strtoupper(trim($smsNumber->provider)) === 'TELNYX', 422);
        $policy = app(VoiceLinePolicyService::class)->forNumber($smsNumber->phone_number);
        abort_unless($policy && $policy->mode === 'staff_voicemail', 422, 'Save staff voicemail routing first.');
        $path = app(VoiceGreetingService::class)->store($request->file('audio')->getRealPath());
        LockedWrite::run(fn () => $policy->update([$request->input('kind').'_greeting' => $path]));

        return response()->json($this->present($smsNumber));
    }

    public function greetingAudio(SmsNumber $smsNumber, string $kind): mixed
    {
        abort_unless(in_array($kind, ['office', 'closed'], true), 404);
        $policy = app(VoiceLinePolicyService::class)->forNumber($smsNumber->phone_number);
        $path = $policy?->{$kind.'_greeting'};
        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('voice_private')->exists($path), 404);

        $response = response()->file(\Illuminate\Support\Facades\Storage::disk('voice_private')->path($path), ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'no-store']);
        $response->setPrivate();

        return $response;
    }
}
