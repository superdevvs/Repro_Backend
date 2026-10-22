<?php

namespace App\Services\Messaging;

use App\Jobs\NotifyShootDeliveryUpdate;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShootDeliveryUpdateNotifier
{
    public function fileSaved(ShootFile $file): void
    {
        $prior = new ShootFile;
        $prior->setRawAttributes($file->getRawOriginal(), true);
        $isNew = $file->wasRecentlyCreated && $file->getRawOriginal('id') === null;
        if ($file->isIguideOfflinePackage()
            || (! $this->published($file) && ($isNew || ! $this->published($prior)))
            || (! $isNew && ! $file->wasChanged([
                'workflow_stage', 'content_version', 'path', 'storage_path', 'file_size', 'scan_status', 'is_hidden',
            ]))) {
            return;
        }
        $shoot = $file->shoot;
        if ($shoot) {
            $this->queue($shoot, $file->shoot_service_id);
        }
    }

    public function fileDeleted(ShootFile $file): void
    {
        if ($this->published($file) && ! $file->isIguideOfflinePackage() && $file->shoot) {
            $this->queue($file->shoot, $file->shoot_service_id);
        }
    }

    private function published(ShootFile $file): bool
    {
        return $file->workflow_stage === ShootFile::STAGE_VERIFIED && ! $file->is_hidden && ! $file->isBlockedFromDelivery();
    }

    public function linksChanged(Shoot $shoot): void
    {
        $packagePublished = $shoot->wasChanged('iguide_data')
            && data_get($shoot->iguide_data, 'manual_offline_package.status') === 'ready'
            && data_get($shoot->iguide_data, 'manual_offline_package.file_id') !== data_get($shoot->getOriginal('iguide_data'), 'manual_offline_package.file_id');
        if ($shoot->wasChanged(['tour_links', 'iguide_tour_url']) || $packagePublished) {
            $this->queue($shoot);
        }
    }

    public function unitChanged(ShootUnit $unit): void
    {
        if (! $unit->wasChanged(['tour_links', 'provider_data']) || ! $unit->shoot) {
            return;
        }
        $prior = new ShootUnit;
        $prior->setRawAttributes($unit->getRawOriginal(), true);
        $revision = app(ShootDeliveryRevision::class);
        if ($revision->unitIdentity($unit) === $revision->unitIdentity($prior)) {
            return;
        }
        $this->queue($unit->shoot);
    }

    private function queue(Shoot $shoot, ?int $serviceId = null): void
    {
        if ($shoot->suppressesExternalNotifications()) {
            return;
        }
        $full = $shoot->workflow_status === Shoot::STATUS_DELIVERED && $shoot->delivery_status === 'delivered';
        if (! $full && ! $shoot->serviceItems()->when($serviceId, fn ($query) => $query->whereKey($serviceId))
            ->where('delivery_status', 'delivered')->where('workflow_status', 'delivered')->exists()) {
            return;
        }
        $shootId = (int) $shoot->id;
        $dispatch = static function () use ($shootId): void {
            try {
                // A batch may change files from several finalized services and
                // their shared tour links. Summarize the current finalized set
                // once per shoot instead of queuing overlapping service scopes.
                NotifyShootDeliveryUpdate::dispatch($shootId);
            } catch (\Throwable $exception) {
                Log::error('Unable to enqueue finalized shoot delivery update.', ['shoot_id' => $shootId, 'error' => $exception->getMessage()]);
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
