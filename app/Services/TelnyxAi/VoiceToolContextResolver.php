<?php

namespace App\Services\TelnyxAi;

use App\Models\Shoot;
use App\Models\VoiceCall;
use Illuminate\Http\Request;

class VoiceToolContextResolver
{
    /** @return array{call:VoiceCall,context:array<string,mixed>}|null */
    public function resolve(Request $request): ?array
    {
        $callControlId = trim((string) $request->header('X-Telnyx-Call-Control-Id', ''));
        if ($callControlId === '') {
            return null;
        }

        $call = VoiceCall::query()
            ->with(['callerUser', 'callerContact.user'])
            ->where('call_control_id', $callControlId)
            ->first();
        if (! $call || in_array(strtolower((string) $call->status), ['completed', 'failed', 'missed', 'cancelled'], true)) {
            return null;
        }

        $user = $call->callerUser ?: $call->callerContact?->user;
        $phone = strtoupper((string) $call->direction) === 'OUTBOUND' ? $call->to_phone : $call->from_phone;

        return [
            'call' => $call,
            'context' => [
                'channel' => 'VOICE',
                'voice_call_id' => $call->id,
                'call_control_id' => $call->call_control_id,
                'telnyx_conversation_id' => $call->telnyx_conversation_id,
                'phone_e164' => $phone,
                'user_id' => $user?->id,
                'contact_id' => $call->caller_contact_id,
                'role' => $user?->role ?? 'contact',
                'verified' => $call->verified_at !== null,
                'verified_at' => $call->verified_at?->toIso8601String(),
                'related_shoot_id' => $call->related_shoot_id,
            ],
        ];
    }

    public function canAccess(string $tool, array $params, VoiceCall $call, array $context): bool
    {
        if (! in_array($tool, ['get_shoot_details', 'get_payment_status', 'reschedule_shoot', 'cancel_shoot', 'create_payment_link'], true)) {
            return true;
        }

        $shootId = (int) ($params['shoot_id'] ?? 0);
        if ($shootId < 1) {
            return false;
        }

        $shoot = Shoot::query()->find($shootId);
        if (! $shoot) {
            // Let the tool return its normal not-found outcome without revealing
            // whether another caller owns the requested ID.
            return true;
        }

        $role = strtolower((string) ($context['role'] ?? ''));
        $userId = (int) ($context['user_id'] ?? 0);
        if (in_array($role, ['admin', 'superadmin', 'editing_manager', 'sales_rep', 'salesrep'], true)) {
            return true;
        }
        if ($role === 'client') {
            return $userId > 0 && (int) $shoot->client_id === $userId;
        }
        if ($role === 'photographer') {
            return $userId > 0 && (int) $shoot->photographer_id === $userId;
        }

        return $call->related_shoot_id !== null && (int) $call->related_shoot_id === $shootId;
    }
}
