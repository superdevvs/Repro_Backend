<?php

namespace App\Services\ListingStudio;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/** The disk is selected by server-owned order association, never a request parameter. */
class ListingStudioPaidStorage extends ListingStudioPrivateStorage
{
    public const DISK = 'listing_studio_orders';

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    public function prepareUploadDirectory(string $id): void
    {
        $this->uuid($id);
        $this->prepare(['uploads', $id]);
    }

    public function prepareItemDirectory(string $id): void
    {
        $this->uuid($id);
        $this->prepare(['items', $id]);
    }

    public function prepareDeliveryDirectory(string $id): void
    {
        $this->uuid($id);
        $this->prepare(['revision-deliveries', $id]);
    }
}
