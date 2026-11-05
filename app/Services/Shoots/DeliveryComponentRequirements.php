<?php

namespace App\Services\Shoots;

use App\Models\ShootService;
use App\Models\Shoot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Booked requirements are immutable snapshots, independent of editing assignments. */
class DeliveryComponentRequirements
{
    /** @return array<int,array{key:string,kind:string,label:string}> */
    public function forItem(ShootService $item): array
    {
        if (is_array($item->delivery_requirements)) return $item->delivery_requirements;
        if (! $item->is_deliverable || data_get($item->payout_snapshot, 'billing_correction_only')
            || data_get($item->payout_snapshot, 'delivery_requirement') === 'financial_only') return [];
        // Preserve reviewed historical aggregate delivery without inferring that
        // today's catalog video/floorplan promises existed at the old booking.
        if ($item->workflow_status === 'delivered' && $item->delivery_status === 'delivered') {
            return [['key' => 'legacy', 'kind' => 'legacy', 'label' => 'Historical delivery']];
        }
        $service = $item->service;
        if ($service?->isDigitalEnhancement()) {
            return collect(app(ShootEditingAssignmentService::class)->serviceEditingLanes($item))->map(fn ($kind) => ['key' => $kind, 'kind' => $kind, 'label' => $kind === 'video' ? 'Video' : 'Photos', 'provider' => null])->all();
        }
        $components = $service?->included_components ?: [['name' => $service?->name ?? 'Service',
            'upload_intake_type' => $service?->uploadIntakeType() ?? 'none']];
        $kinds = []; $providers = [];
        $context = strtolower(($service?->name ?? '').' '.implode(' ', array_column($components, 'name')));
        $contextProvider = str_contains($context, 'iguide') ? 'iguide' : (str_contains($context, 'matterport') ? 'matterport' : (str_contains($context, 'zillow') ? 'zillow' : 'cubicasa'));
        foreach ($components as $component) {
            $intake = $component['upload_intake_type'] ?? 'none';
            if (in_array($intake, ['photo', 'photo_video'], true)) $kinds['photo'] = 'Photos';
            if (in_array($intake, ['video', 'photo_video'], true)) $kinds['video'] = 'Video';
            if ($intake !== 'none') continue;
            // Seed explicit provider requirements from the booked inclusion.
            // Subsequent catalog renames cannot change this snapshot.
            $name = strtolower((string) ($component['name'] ?? ''));
            $provider = str_contains($name, 'iguide') ? 'iguide' : (str_contains($name, 'matterport') ? 'matterport' : (str_contains($name, 'zillow') ? 'zillow' : $contextProvider));
            if (preg_match('/floor\s*plans?|cubicasa/', $name)) { $kinds['floorplan'] = 'Floorplans'; $providers['floorplan'] = $provider; }
            if (preg_match('/iguide|zillow|matterport|\b3d\b|tour/', $name)) { $kinds['tour'] = 'Property tour'; $providers['tour'] = $provider; }
            if (! preg_match('/floor\s*plans?|cubicasa|iguide|zillow|matterport|\b3d\b|tour/', $name)) $kinds['external'] = $component['name'] ?? 'External delivery';
        }
        return collect($kinds)->map(fn ($label, $kind) => ['key' => $kind, 'kind' => $kind, 'label' => $label, 'provider' => $providers[$kind] ?? null])->values()->all();
    }

    public function kinds(ShootService $item): array { return array_column($this->forItem($item), 'kind'); }

    public function ensure(ShootService $item): void
    {
        if ($item->delivery_requirements === null) $item->delivery_requirements = $this->forItem($item);
    }

    /** Only an explicit execution-state reset reopens work; labels/revisions do not. */
    public function reopenIfRequested(ShootService $item, ?Shoot $shoot): bool
    {
        if (! $shoot || ! $item->exists || ! $item->isDirty(['workflow_status', 'delivery_status'])
            || $item->getRawOriginal('workflow_status') !== 'delivered' || $item->getRawOriginal('delivery_status') !== 'delivered'
            || ($item->workflow_status === 'delivered' && $item->delivery_status === 'delivered')
            || $item->cancelled_at || $item->workflow_status === 'cancelled' || $item->delivery_status === 'cancelled'
            || ! $item->is_deliverable) return false;
        $requirements = $this->forItem($item);
        if (collect($requirements)->contains(fn ($component) => $component['kind'] === 'legacy')) {
            $policy = app(DeliveryPlanService::class);
            $at = $item->delivered_at ?? $shoot->admin_verified_at ?? $shoot->completed_at;
            if ($at) {
                $published = \App\Models\ShootFileVersion::where('shoot_id', $shoot->id)->where('status', 'published')
                    ->where('updated_at', '<=', $at)->get()->groupBy('published_file_id');
                $files = $shoot->files()->where('shoot_service_id', $item->id)->where('workflow_stage', 'verified')->get()
                    ->filter(fn ($file) => $policy->usableOutput($file) && $file->verified_at && $file->verified_at->lte($at)
                        && ((int) ($file->content_version ?: 1) === 1 || $published->get($file->id, collect())->contains(fn ($version) => (int) $version->version === (int) $file->content_version)));
                foreach ($files->groupBy(fn ($file) => $policy->contentKind($file)) as $kind => $group) {
                    if (! $kind) continue;
                    $evidence = ['files' => $group->map(fn ($file) => ['id' => $file->id, 'version' => (int) ($file->content_version ?: 1)])->values()->all(),
                        'historical_review' => ['workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => $at->toIso8601String()]];
                    DB::table('shoot_delivery_receipts')->insertOrIgnore(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
                        'component_key' => $kind, 'execution_key' => 'history:'.$at->toIso8601String(), 'method' => 'media',
                        'evidence' => json_encode($evidence), 'actor_id' => null, 'reason' => 'Preserved verified historical output before explicit service reopening.',
                        'plan_revision' => hash('sha256', json_encode($evidence)), 'operation_key' => 'legacy-history-'.$item->id, 'delivered_at' => $at]);
                }
            }
            $item->delivery_requirements = null;
            $requirements = $this->forItem($item);
        }
        $execution = 'line:'.$item->id.':'.Str::uuid();
        $item->delivery_requirements = array_map(fn ($component) => array_replace($component,
            ['execution_key' => $execution, 'execution_started_at' => now()->toIso8601String()]), $requirements);
        return true;
    }
}
