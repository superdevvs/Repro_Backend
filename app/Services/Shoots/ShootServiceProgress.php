<?php

namespace App\Services\Shoots;

use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Services\Aryeo\AryeoCatalog;
use Illuminate\Support\Collection;

/** Read-only progress labels. Never changes delivery, payment, or provider state. */
class ShootServiceProgress
{
    public function forItems(Shoot $shoot, Collection $items): array
    {
        $showcase = $items->filter(fn ($item) => $this->kind($item) === 'showcase');
        $orders = $showcase->isEmpty() ? collect() : AryeoRequest::where('shoot_id', $shoot->id)
            ->where('match_status', 'matched')->orderByDesc('id')->get();
        $jobs = $orders->isEmpty() ? collect() : AryeoJob::whereIn('request_record_id', $orders->pluck('id'))
            ->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('request_record_id');

        return $items->mapWithKeys(function (ShootService $item) use ($shoot, $orders, $jobs) {
            $order = $orders->first(fn ($order) => (string) $order->unit_id === (string) $item->shoot_unit_id);
            return [$item->id => $this->forItem($shoot, $item, $order, $order ? $jobs->get($order->id, collect())->first() : null)];
        })->all();
    }

    public function forItem(Shoot $shoot, ShootService $item, ?AryeoRequest $order = null, ?AryeoJob $job = null): string
    {
        $workflow = strtolower((string) $item->workflow_status);
        $delivery = strtolower((string) $item->delivery_status);
        $shootState = strtolower((string) ($shoot->workflow_status ?: $shoot->status));
        if ($workflow === 'cancelled' || $delivery === 'cancelled' || $item->cancelled_at) return 'cancelled';

        // A verified external delivery has its own identity and receipt. The
        // Dashboard shoot's Delivered flag is not proof of delivery to Aryeo.
        $kind = $this->kind($item);
        if ($kind === 'showcase' && $order) return $this->showcase($shoot, $order, $job);
        if ($delivery === 'delivered' || $workflow === 'delivered' || $item->delivered_at) return 'delivered';
        if ($shootState === 'cancelled') return 'cancelled';
        if (in_array($shootState, ['on_hold', 'hold_on', 'declined'], true)) return 'on_hold';
        if ($kind === 'showcase') return 'received';
        if ($delivery === 'ready' || $workflow === 'ready' || $item->ready_at) return 'ready';

        if (in_array($kind, ['floorplan', 'tour'], true)) {
            $provider = $this->providerData($shoot, $item);
            $state = strtolower((string) ($provider['cubicasa_status'] ?? ''));
            if (in_array($state, ['failed', 'error', 'rejected'], true)) return 'needs_attention';
            if ($state === 'cancelled') return 'cancelled';
            $plans = array_merge($provider['cubicasa_floorplans'] ?? [], $provider['iguide_floorplans'] ?? []);
            $links = $provider['tour_links'] ?? [];
            $hasTour = collect(['zillow_3d', 'matterport_branded', 'matterport_mls', 'iguide_branded', 'iguide_mls'])
                ->contains(fn ($key) => $this->isUrl($links[$key] ?? null));
            $hasTour = $hasTour || $this->isUrl($provider['iguide_tour_url'] ?? null)
                || $this->isUrl($provider['cubicasa_tour_url'] ?? null);
            // Provider output can be ready before it is released to the client.
            if (($kind === 'floorplan' && ! empty($plans)) || ($kind === 'tour' && $hasTour)) return 'ready';
            if ($state !== '' || ! empty($provider['cubicasa_order_id']) || ! empty($provider['iguide_work_order_id'])
                || $workflow === 'in_progress') return $kind === 'floorplan' ? 'drafting' : 'processing';
            return 'pending';
        }

        $editing = app(ShootEditingAssignmentService::class);
        $lanes = $editing->serviceEditingLanes($item);
        $photoPending = in_array('photo', $lanes, true) && ! $item->editing_completed_at;
        $videoCompleted = count($lanes) > 1 ? $item->video_editing_completed_at : $item->editing_completed_at;
        $videoPending = in_array('video', $lanes, true) && ! $videoCompleted;
        if ($lanes !== [] && ! $photoPending && ! $videoPending) return 'ready';
        if ($workflow === 'in_progress' || ($shootState === 'editing'
            && (($photoPending && $item->editor_id) || ($videoPending && ($item->video_editor_id ?: $item->editor_id))))) return 'in_editing';
        return 'pending';
    }

    /** Classification affects display text only, never intake or authorization. */
    private function kind(ShootService $item): string
    {
        $service = $item->service;
        $name = strtolower(($service?->name ?? '').' '.($service?->category?->name ?? ''));
        if (str_contains($name, 'showcase') || str_contains($name, 'aryeo')) return 'showcase';
        // Photo/video bundles retain their actual service workflow; a provider
        // link for one component must not finish the other components.
        if ($service?->supportsPhotoIntake() || $service?->supportsVideoIntake()) return 'media';
        if (preg_match('/iguide|zillow|matterport|\b3d\b/', $name)) return 'tour';
        if (preg_match('/floor\s*plans?|cubicasa/', $name)) return 'floorplan';
        return 'media';
    }

    private function providerData(Shoot $shoot, ShootService $item): array
    {
        if ($item->shoot_unit_id) {
            // Unit/line provider identities are independent. Never borrow the
            // whole-shoot provider's ready state for another apartment.
            $data = $item->unit?->provider_data ?? [];
            foreach (['iguide', 'cubicasa'] as $provider) {
                if (isset($data[$provider.'_service_line_id']) && (int) $data[$provider.'_service_line_id'] !== (int) $item->id) {
                    foreach (array_keys($data) as $key) if (str_starts_with($key, $provider.'_')) unset($data[$key]);
                }
            }
            $data = array_replace($data, data_get($item->unit?->provider_data, 'lines.'.$item->id, []));
            return $data + ['tour_links' => $item->unit?->tour_links ?? []];
        }
        return collect(['cubicasa_status', 'cubicasa_order_id', 'cubicasa_floorplans', 'cubicasa_tour_url',
            'iguide_floorplans', 'iguide_work_order_id', 'iguide_tour_url', 'tour_links'])
            ->mapWithKeys(fn ($key) => [$key => $shoot->getAttribute($key)])->all();
    }

    private function showcase(Shoot $shoot, AryeoRequest $order, ?AryeoJob $job): string
    {
        $ready = app(AryeoCatalog::class)->readiness($shoot, $order->unit_id, $order->discovery['required'] ?? null);
        if ($job && ($job->steps['delivery'] ?? null) === 'success' && $this->verifiedReceipt($order, $job, $ready)) return 'delivered';
        if ($this->verifiedManualDelivery($order, $ready)) return 'delivered';
        if ($job && in_array($job->status, ['queued', 'running', 'reconciling'], true)) return 'processing';
        if ($job?->status === 'failed') return 'needs_attention';
        if ($job?->status === 'cancelled') return 'cancelled';
        if (array_intersect(['missing_tours', 'missing_floorplans'], $ready['blockers'])) return 'awaiting_assets';
        // Completed jobs for an older media version do not complete an update.
        return 'received';
    }

    private function verifiedReceipt(AryeoRequest $order, AryeoJob $job, array $ready): bool
    {
        $receipt = $job->receipt ?? [];
        return $this->verifiedTime($receipt['verified_at'] ?? null) && ! empty($receipt['listing_id'])
            && $receipt['listing_id'] === $order->listing_id
            && ($receipt['request_id'] ?? null) === $order->request_id
            && ($receipt['media_version'] ?? null) === $ready['media_version']
            && $job->media_version === $ready['media_version']
            && ! array_diff(array_map('strval', array_column($ready['assets'], 'id')), array_map('strval', $receipt['asset_ids'] ?? []))
            && ! array_diff(array_column($ready['tours'], 'id'), $receipt['tour_ids'] ?? []);
    }

    private function verifiedManualDelivery(AryeoRequest $order, array $ready): bool
    {
        $inventory = $order->inventory ?? [];
        $receipt = $inventory['manual_delivery_receipt'] ?? [];
        if (($receipt['mode'] ?? '') !== 'supervised' || empty($receipt['operation_id']) || ! $order->listing_id
            || ($receipt['listing_id'] ?? null) !== $order->listing_id
            || ($receipt['media_version'] ?? null) !== $ready['media_version']
            || ! $this->verifiedTime($receipt['verified_at'] ?? null) || ! $order->inventory_checked_at || empty($inventory['complete'])) return false;
        $expected = array_map('strval', array_column($ready['assets'], 'id'));
        $expectedTours = array_column($ready['tours'], 'id');
        $actual = array_map('strval', $receipt['asset_ids'] ?? []);
        $actualTours = $receipt['tour_ids'] ?? [];
        if ($expected === [] && $expectedTours === []) return false;
        if (count($actual) !== count(array_unique($actual)) || count($actualTours) !== count(array_unique($actualTours))) return false;
        if (array_diff($actual, $expected) || array_diff($expected, $actual)
            || array_diff($actualTours, $expectedTours) || array_diff($expectedTours, $actualTours)) return false;
        $remote = collect($inventory['assets'] ?? []);
        $files = $remote->where('type', '!=', 'tours');
        $expectedTypes = collect($ready['assets'])->mapWithKeys(fn ($asset) => [(string) $asset['id'] => $asset['type']]);
        return $remote->count() === count($expected) + count($expectedTours)
            && $remote->pluck('id')->unique()->count() === $remote->count()
            && $files->count() === count($expected)
            && $files->pluck('dashboard_asset_id')->unique()->count() === count($expected)
            && $files->every(fn ($asset) => isset($asset['dashboard_asset_id'])
                && $expectedTypes->get((string) $asset['dashboard_asset_id']) === ($asset['type'] ?? null))
            && $remote->where('type', 'tours')->count() === count($expectedTours)
            && $remote->every(fn ($asset) => ($asset['delivered'] ?? false) === true);
    }

    private function isUrl(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value);
    }

    private function verifiedTime(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') return false;
        try { return \Carbon\CarbonImmutable::parse($value)->lte(now()->addMinute()); }
        catch (\Throwable) { return false; }
    }
}
