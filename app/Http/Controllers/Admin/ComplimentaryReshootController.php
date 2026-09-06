<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateComplimentaryReshootRequest;
use App\Http\Requests\StoreShootCompensationAdjustmentRequest;
use App\Http\Requests\UpdateShootCompensationsRequest;
use App\Http\Resources\ComplimentaryReshootResource;
use App\Http\Resources\ShootCompensationResource;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Services\AuditLogService;
use App\Services\Shoots\ComplimentaryReshootReasonPolicy;
use App\Services\Shoots\ComplimentaryReshootService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplimentaryReshootController extends Controller
{
    public function __construct(
        private readonly ComplimentaryReshootService $complimentaryReshoots,
        private readonly AuditLogService $auditLog,
    ) {}

    public function template(Request $request, Shoot $sourceShoot): JsonResponse
    {
        $reasonCode = $request->query('reason_code');
        if ($reasonCode !== null && ! in_array($reasonCode, ComplimentaryReshootReasonPolicy::REASONS, true)) {
            return response()->json([
                'message' => 'The selected reason code is invalid.',
                'errors' => ['reason_code' => ['Select a valid complimentary-reshoot reason.']],
            ], 422);
        }

        return response()->json([
            'data' => $this->complimentaryReshoots->template($sourceShoot, $reasonCode),
        ]);
    }

    public function store(CreateComplimentaryReshootRequest $request, Shoot $sourceShoot): JsonResponse
    {
        try {
            $result = $this->complimentaryReshoots->create(
                $sourceShoot,
                $request->validated(),
                $request->user()
            );
        } catch (\DomainException $exception) {
            return $this->conflict($exception);
        }

        if (! $result['replayed']) {
            $this->auditLog->record('complimentary_reshoot.created', $request->user(), $result['shoot'], [
                'reshoot_of_shoot_id' => $result['shoot']->reshoot_of_shoot_id,
                'root_shoot_id' => $result['shoot']->root_shoot_id,
                'idempotency_key' => $result['shoot']->complimentary_reshoot_idempotency_key,
            ]);
        }

        return (new ComplimentaryReshootResource($result['shoot']))
            ->additional(['meta' => [
                'replayed' => (bool) $result['replayed'],
                'idempotency_key' => $result['shoot']->complimentary_reshoot_idempotency_key,
            ]])
            ->response()
            ->setStatusCode($result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, Shoot $shoot): ComplimentaryReshootResource
    {
        $user = $request->user();
        $roles = collect([(string) $user?->role])
            ->merge(is_array($user?->secondary_roles) ? $user->secondary_roles : [])
            ->map(fn ($role) => strtolower(str_replace(['_', '-'], '', (string) $role)))
            ->filter()
            ->unique();
        $canManage = $roles->contains(fn (string $role) => in_array($role, ['admin', 'superadmin'], true));
        if (! $canManage) {
            $recipientTypes = collect()
                ->when(
                    $roles->contains('photographer'),
                    fn ($types) => $types->push(ShootCompensation::RECIPIENT_PHOTOGRAPHER)
                )
                ->when(
                    $roles->contains('salesrep'),
                    fn ($types) => $types->push(ShootCompensation::RECIPIENT_SALES_REP)
                );
            $hasOwnDecision = $shoot->isComplimentaryReshoot()
                && $shoot->compensations()
                    ->where('recipient_user_id', $user?->id)
                    ->whereIn('recipient_type', $recipientTypes->all())
                    ->exists();
            abort_unless($hasOwnDecision, 404);
        }

        return new ComplimentaryReshootResource($this->complimentaryReshoots->get($shoot));
    }

    public function update(UpdateShootCompensationsRequest $request, Shoot $shoot): JsonResponse
    {
        try {
            $updated = $this->complimentaryReshoots->updateCompensations(
                $shoot,
                $request->validated('compensations'),
                $request->user()
            );
        } catch (\DomainException $exception) {
            return $this->conflict($exception);
        }

        $this->auditLog->record('complimentary_reshoot.compensations_updated', $request->user(), $updated, [
            'compensation_ids' => collect($request->validated('compensations'))->pluck('id')->values()->all(),
        ]);

        return (new ComplimentaryReshootResource($updated))->response();
    }

    public function storeAdjustment(
        StoreShootCompensationAdjustmentRequest $request,
        Shoot $shoot,
        ShootCompensation $compensation
    ): JsonResponse {
        try {
            $result = $this->complimentaryReshoots->createCompensationAdjustment(
                $shoot,
                $compensation,
                $request->validated(),
                $request->user()
            );
        } catch (\DomainException $exception) {
            return $this->conflict($exception);
        }

        if (! $result['replayed']) {
            $this->auditLog->record(
                'complimentary_reshoot.compensation_adjustment_created',
                $request->user(),
                $shoot,
                [
                    'original_compensation_id' => $compensation->id,
                    'adjustment_compensation_id' => $result['compensation']->id,
                    'line_type' => $result['compensation']->line_type,
                    'amount' => round((float) $result['compensation']->amount, 2),
                    'note' => $request->validated('note'),
                ]
            );
        }

        return (new ShootCompensationResource(
            $result['compensation']->load(['recipient', 'serviceItem.service', 'invoiceItem.invoice'])
        ))->additional(['meta' => ['replayed' => (bool) $result['replayed']]])
            ->response()
            ->setStatusCode($result['replayed'] ? 200 : 201);
    }

    private function conflict(\DomainException $exception): JsonResponse
    {
        return response()->json([
            'message' => \App\Services\ApiErrorResponder::publicMessage($exception),
        ], 409);
    }
}
