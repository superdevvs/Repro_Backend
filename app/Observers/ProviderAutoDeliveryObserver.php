<?php

namespace App\Observers;

use App\Models\ShootFile;
use App\Jobs\ProcessProviderAutoDelivery;

/** Scan, preview and provider revision completion all retry the same guarded worker. */
class ProviderAutoDeliveryObserver
{
    public function saved(ShootFile $file): void
    {
        if (! in_array(data_get($file->metadata, 'source'), ['iguide', 'cubicasa'], true) || ! $file->shoot_service_id
            || ! $file->wasChanged(['scan_status', 'web_path', 'content_version', 'shoot_service_id'])) return;
        $shoot = $file->shoot;
        if ($shoot && ! app(\App\Services\Shoots\ProviderAutoDeliveryService::class)->blocked($shoot)
            && app(\App\Services\Shoots\ProviderAutoDeliverySettings::class)->supportedItems($shoot)->contains('id', $file->shoot_service_id))
            ProcessProviderAutoDelivery::dispatch($shoot->id)->afterCommit();
    }
}
