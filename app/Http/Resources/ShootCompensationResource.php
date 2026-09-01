<?php

namespace App\Http\Resources;

use App\Models\ShootCompensation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShootCompensationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShootCompensation $compensation */
        $compensation = $this->resource;
        $user = $request->user();
        $roles = collect([(string) ($user?->role ?? '')])
            ->merge(is_array($user?->secondary_roles) ? $user->secondary_roles : [])
            ->map(fn ($value) => strtolower(str_replace(['_', '-'], '', (string) $value)))
            ->filter()
            ->unique();
        $canManage = $roles->contains(fn (string $value) => in_array($value, ['admin', 'superadmin'], true));
        $isRecipient = $user && (int) $user->id === (int) $compensation->recipient_user_id;
        $canSeeDecision = $canManage || $isRecipient;
        $invoiceItem = $compensation->relationLoaded('invoiceItem')
            ? $compensation->invoiceItem
            : null;

        return [
            'id' => $compensation->id,
            'shoot_id' => $compensation->shoot_id,
            'shoot_service_id' => $compensation->shoot_service_id,
            'scope' => $compensation->shoot_service_id ? 'service' : 'shoot',
            'line_type' => $this->when(
                $canSeeDecision,
                $compensation->line_type ?? ShootCompensation::LINE_TYPE_BASE
            ),
            'adjusts_compensation_id' => $this->when($canSeeDecision, $compensation->adjusts_compensation_id),
            'recipient_type' => $compensation->recipient_type,
            'recipient' => $this->when($canSeeDecision && $compensation->recipient, fn () => [
                'id' => $compensation->recipient->id,
                'name' => $compensation->recipient->name,
            ]),
            'service' => $this->when($compensation->serviceItem?->service, fn () => [
                'id' => $compensation->serviceItem->service->id,
                'name' => $compensation->serviceItem->service->name,
            ]),
            'mode' => $this->when($canSeeDecision, $compensation->mode),
            'amount' => $this->when($canSeeDecision, round((float) $compensation->amount, 2)),
            'currency' => $this->when($canSeeDecision, $compensation->currency),
            'payout_status' => $this->when($canSeeDecision, $compensation->payout_status),
            'suggested_mode' => $this->when($canManage, $compensation->suggested_mode),
            'suggested_amount' => $this->when(
                $canManage,
                $compensation->suggested_amount === null
                    ? null
                    : round((float) $compensation->suggested_amount, 2)
            ),
            'calculation_method' => $this->when($canManage, $compensation->calculation_method),
            'quantity_snapshot' => $this->when($canManage, (int) $compensation->quantity_snapshot),
            'basis_amount_snapshot' => $this->when(
                $canManage,
                $compensation->basis_amount_snapshot === null
                    ? null
                    : round((float) $compensation->basis_amount_snapshot, 2)
            ),
            'rate_snapshot' => $this->when(
                $canManage,
                $compensation->rate_snapshot === null ? null : (float) $compensation->rate_snapshot
            ),
            'standard_calculation_method' => $this->when($canManage, $compensation->standard_calculation_method),
            'standard_rate_snapshot' => $this->when(
                $canManage,
                $compensation->standard_rate_snapshot === null
                    ? null
                    : (float) $compensation->standard_rate_snapshot
            ),
            'standard_amount_snapshot' => $this->when(
                $canManage,
                round((float) $compensation->standard_amount_snapshot, 2)
            ),
            'reason_code' => $this->when($canManage, $compensation->reason_code),
            'policy_version' => $this->when($canManage, $compensation->policy_version),
            'earned_at' => $this->when($canSeeDecision, $compensation->earned_at?->toIso8601String()),
            'locked_at' => $this->when($canSeeDecision, $compensation->locked_at?->toIso8601String()),
            'invoice_id' => $this->when($canSeeDecision, $invoiceItem?->invoice_id),
            'can_edit' => $this->when(
                $canManage,
                ($compensation->line_type ?? ShootCompensation::LINE_TYPE_BASE) === ShootCompensation::LINE_TYPE_BASE
                    && ! $compensation->locked_at
                    && ! $compensation->isSettlementLocked()
            ),
            'updated_at' => $this->when($canManage, $compensation->updated_at?->toIso8601String()),
        ];
    }
}
