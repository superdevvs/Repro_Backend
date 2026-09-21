<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\SmsNumber;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoiceNumberController extends Controller
{
    public function index(VoiceNumberSettingsResolver $numbers): JsonResponse
    {
        return response()->json([
            'numbers' => $numbers->canonicalNumbers()->map(fn (SmsNumber $number) => $number->only([
                'id', 'phone_number', 'label', 'is_default', 'voice_ai_enabled', 'voice_assistant_id_override', 'sms_ai_enabled',
            ])),
        ]);
    }

    public function update(Request $request, SmsNumber $smsNumber, VoiceNumberSettingsResolver $numbers): JsonResponse
    {
        $data = $request->validate([
            'voice_ai_enabled' => ['sometimes', 'nullable', 'boolean'],
            'voice_assistant_id_override' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sms_ai_enabled' => ['sometimes', 'nullable', 'boolean'],
        ]);

        abort_unless(strtoupper(trim((string) $smsNumber->provider)) === 'TELNYX', 422, 'This is not a Telnyx voice number.');
        $canonical = LockedWrite::run(fn () => DB::transaction(function () use ($smsNumber, $numbers, $data): SmsNumber {
            $duplicates = $numbers->matching($smsNumber->phone_number, $smsNumber->provider);
            abort_if($duplicates->isEmpty(), 422, 'This number has no valid phone address.');
            // These are physical-line switches, even when historical message
            // rows reference different owner records. Keep all identities/FKs.
            SmsNumber::query()->whereIn('id', $duplicates->modelKeys())->update($data);

            return $duplicates->first()->fresh();
        }), 'voice.number.update');

        return response()->json($canonical);
    }
}
