<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootGuestShare;
use App\Support\ShootAddress;
use Illuminate\Support\Collection;

/** An explicit guest allowlist, never the authenticated shoot presenter. */
class ShootGuestOverview
{
    public function allowsFile(Shoot $shoot, ShootFile $file): bool
    {
        return (int) $file->shoot_id === (int) $shoot->id
            && app(ShootClientReleaseAccessService::class)->isApprovedFile($shoot, $file)
            && app(PublicTourImage::class)->canRetainPublishedFile($file, $shoot);
    }

    public function files(Shoot $shoot): Collection
    {
        $files = $shoot->files()->with('serviceItem')->get()->filter(fn (ShootFile $file) => $this->allowsFile($shoot, $file));
        return app(DeliveryMediaOrderService::class)->applyTo($shoot, $files)->values();
    }

    public function build(ShootGuestShare $share, string $token): array
    {
        $shoot = $share->shoot;
        $plan = app(DeliveryPlanService::class);
        $files = $this->files($shoot);
        $details = $shoot->property_details ?? [];
        $tours = app(ShootGuestTours::class)->build($shoot, $files, $token);
        $items = $shoot->serviceItems()->with('service')->get()->keyBy('id');
        $summaries = $plan->componentSummaries($shoot);
        return [
            'property' => [
                'address' => ShootAddress::streetWithAptSuite($shoot->address, $details),
                'city' => $shoot->city, 'state' => $shoot->state, 'zip' => $shoot->zip,
                'beds' => $details['bedrooms'] ?? $details['beds'] ?? null,
                'baths' => $details['bathrooms'] ?? $details['baths'] ?? null,
                'sqft' => $details['sqft'] ?? $details['squareFeet'] ?? null,
            ],
            'services' => $items->filter(fn ($item) => $item->is_deliverable && ! $item->cancelled_at)
                ->map(fn ($item) => ['name' => $item->service?->name,
                    'components' => $summaries[$item->id] ?? [['label' => 'Delivery', 'status' => $item->delivery_status]]])->values()->all(),
            'files' => $files->map(fn ($file) => [
                'id' => $file->id, 'name' => $file->filename,
                'kind' => $plan->contentKind($file) ?? 'file',
                'preview_url' => route('api.guest-shares.file', ['token' => $token, 'file' => $file->id]),
                'download_url' => route('api.guest-shares.file', ['token' => $token, 'file' => $file->id, 'download' => 1]),
                'mls_download_url' => app(ShootGuestArchiveFiles::class)->mlsPath($file)
                    ? route('api.guest-shares.file-mls', ['token' => $token, 'file' => $file->id]) : null,
                'mime_type' => $file->mime_type ?: $file->file_type,
                'size_bytes' => is_numeric($file->file_size) ? (int) $file->file_size : null,
                'width' => is_numeric(data_get($file->metadata, 'width')) ? (int) data_get($file->metadata, 'width') : null,
                'height' => is_numeric(data_get($file->metadata, 'height')) ? (int) data_get($file->metadata, 'height') : null,
            ])->all(),
            'tours' => collect($tours)->unique('url')->values()->all(),
            'expires_at' => $share->expires_at->toIso8601String(),
        ];
    }
}
