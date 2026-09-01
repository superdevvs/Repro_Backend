<?php

namespace App\Http\Resources;

use App\Models\ShootCompensation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplimentaryReshootResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing([
            'client:id,name,email',
            'rep:id,name,email,role',
            'reshootOf:id,address,city,state,zip,shoot_type,reshoot_of_shoot_id,root_shoot_id',
            'rootShoot:id,address,city,state,zip,shoot_type',
            'serviceItems.service',
            'serviceItems.photographer:id,name,email,role',
            'compReshootItems.responsibleStaff:id,name,email,role',
            'compReshootItems.sourceServiceItem.service',
            'compensations.recipient:id,name,email,role',
            'compensations.serviceItem.service',
            'compensations.invoiceItem.invoice',
            'editorPayouts',
        ]);

        $user = $request->user();
        $role = strtolower((string) ($user?->role ?? ''));
        $roles = collect([$role])
            ->merge(is_array($user?->secondary_roles) ? $user->secondary_roles : [])
            ->map(fn ($value) => strtolower(str_replace(['_', '-'], '', (string) $value)))
            ->filter()
            ->unique();
        $canManage = $roles->contains(fn (string $value) => in_array($value, ['admin', 'superadmin'], true));
        $isPhotographer = $roles->contains('photographer');
        $isSalesRep = $roles->contains('salesrep');
        $visibleCompensations = $this->compensations
            ->filter(function ($compensation) use ($canManage, $isPhotographer, $isSalesRep, $user) {
                if ($canManage) {
                    return true;
                }

                if (! $user || (int) $compensation->recipient_user_id !== (int) $user->id) {
                    return false;
                }

                return ($isPhotographer && $compensation->recipient_type === ShootCompensation::RECIPIENT_PHOTOGRAPHER)
                    || ($isSalesRep && $compensation->recipient_type === ShootCompensation::RECIPIENT_SALES_REP);
            })
            ->values();
        $photographerCompensations = $visibleCompensations
            ->where('recipient_type', ShootCompensation::RECIPIENT_PHOTOGRAPHER)
            ->values();
        $salesRepCompensation = $visibleCompensations
            ->firstWhere('recipient_type', ShootCompensation::RECIPIENT_SALES_REP);
        $nominalTotal = round((float) $this->serviceItems->sum('nominal_value_snapshot'), 2);
        $staffPayTotal = round((float) $this->compensations
            ->filter(fn ($compensation) => ! $compensation->voided_at)
            ->sum(fn ($compensation) => (float) $compensation->amount), 2);
        $editorActualTotal = round((float) $this->editorPayouts->sum('payout_amount'), 2);
        $editingServiceCount = $this->serviceItems
            ->filter(fn ($item) => $item->service?->requiresEditing())
            ->count();
        $editorPayoutCount = $this->editorPayouts->count();
        $editorCostStatus = match (true) {
            $editingServiceCount === 0 => 'not_applicable',
            $editorPayoutCount === 0 => 'pending',
            $editorPayoutCount < $editingServiceCount => 'partial',
            default => 'final',
        };
        $companyCostActualToDate = round($staffPayTotal + $editorActualTotal, 2);

        return [
            'id' => $this->id,
            'shoot_type' => $this->shoot_type,
            'status' => $this->status,
            'workflow_status' => $this->workflow_status,
            'delivery_status' => $this->delivery_status,
            'payment_status' => $this->payment_status,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'lineage' => [
                'parent' => $this->shootSummary($this->reshootOf),
                'root' => $this->shootSummary($this->rootShoot),
            ],
            'client' => $this->when($canManage && $this->client, fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
            ]),
            'property' => [
                'address' => $this->address,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip,
            ],
            'client_charge' => [
                'subtotal' => 0.0,
                'tax' => 0.0,
                'total' => 0.0,
                'payment_required' => false,
            ],
            'service_items' => $this->serviceItems
                ->filter(function ($item) use ($canManage, $isPhotographer, $isSalesRep, $user) {
                    if ($canManage) {
                        return true;
                    }

                    if (! $user) {
                        return false;
                    }

                    return ($isPhotographer && (int) $item->photographer_id === (int) $user->id)
                        || ($isSalesRep && (int) $this->rep_id === (int) $user->id);
                })
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'service' => $item->service ? [
                        'id' => $item->service->id,
                        'name' => $item->service->name,
                    ] : null,
                    'quantity' => (int) $item->quantity,
                    'client_price' => 0.0,
                    'nominal_value_snapshot' => $canManage
                        ? round((float) $item->nominal_value_snapshot, 2)
                        : null,
                    'photographer' => $item->photographer ? [
                        'id' => $item->photographer->id,
                        'name' => $item->photographer->name,
                    ] : null,
                    'scheduled_at' => $item->scheduled_at?->toIso8601String(),
                    'delivery_status' => $item->delivery_status,
                ])
                ->values(),
            'affected_source_items' => $this->when($canManage, fn () => $this->compReshootItems
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'shoot_service_id' => $item->shoot_service_id,
                    'source_shoot_service_id' => $item->source_shoot_service_id,
                    'source_service' => [
                        'id' => $item->source_service_id_snapshot,
                        'name' => $item->source_service_name_snapshot,
                    ],
                    'reason_code' => $item->reason_code,
                    'reason_note' => $item->reason_note,
                    'responsibility' => $item->responsibility,
                    'responsible_staff' => $item->responsibleStaff ? [
                        'id' => $item->responsibleStaff->id,
                        'name' => $item->responsibleStaff->name,
                    ] : null,
                ])
                ->values()),
            'photographer_compensations' => ShootCompensationResource::collection($photographerCompensations),
            'sales_rep_compensation' => $salesRepCompensation
                ? new ShootCompensationResource($salesRepCompensation)
                : null,
            'compensations' => ShootCompensationResource::collection($visibleCompensations),
            'financial_summary' => $this->when($canManage, [
                'client_total' => 0.0,
                'nominal_value_total' => $nominalTotal,
                'staff_pay_total' => $staffPayTotal,
                'editor_payout_actual_total' => $editorActualTotal,
                'editor_cost_status' => $editorCostStatus,
                'editor_cost_estimate' => null,
                'company_cost_actual_to_date' => $companyCostActualToDate,
                'company_cost_total' => $editorCostStatus === 'pending' || $editorCostStatus === 'partial'
                    ? null
                    : $companyCostActualToDate,
                'company_cost_is_final' => in_array($editorCostStatus, ['not_applicable', 'final'], true),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function shootSummary($shoot): ?array
    {
        if (! $shoot) {
            return null;
        }

        return [
            'id' => $shoot->id,
            'shoot_type' => $shoot->shoot_type,
            'address' => $shoot->address,
            'city' => $shoot->city,
            'state' => $shoot->state,
            'zip' => $shoot->zip,
        ];
    }
}
