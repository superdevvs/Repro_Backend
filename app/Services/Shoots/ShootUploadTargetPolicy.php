<?php

namespace App\Services\Shoots;

use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Shared ownership and content-family boundary for every media intake transport. */
final class ShootUploadTargetPolicy
{
    public function isWorkItem(ShootService $item): bool
    {
        return $item->is_deliverable && ! $item->cancelled_at
            && ! in_array(strtolower((string) $item->workflow_status), ['cancelled', 'canceled', 'declined'], true)
            && ! in_array(strtolower((string) $item->delivery_status), ['cancelled', 'canceled'], true)
            && ! data_get($item->payout_snapshot, 'billing_correction_only', false)
            && ! app(FinancialServiceClassification::class)->isFinancialOnly($item);
    }

    public function isOutstanding(ShootService $item): bool
    {
        return $this->isWorkItem($item) && ! ($item->workflow_status === 'delivered' && $item->delivery_status === 'delivered');
    }

    public function kinds(ShootService $item): array
    {
        $requirements = app(DeliveryComponentRequirements::class)->forItem($item);
        if (array_column($requirements, 'kind') === ['legacy']) {
            // Compatibility describes permissible replacement bytes, not a new
            // historical delivery obligation or approval.
            $kinds = [];
            if ($item->service?->supportsPhotoIntake()) $kinds[] = 'photo';
            if ($item->service?->supportsVideoIntake()) $kinds[] = 'video';
            if ($item->shoot?->files()->where('shoot_service_id', $item->id)->where('media_type', 'floorplan')->exists()) $kinds[] = 'floorplan';
            return $kinds;
        }

        return array_values(array_unique(array_filter(array_column($requirements, 'kind'))));
    }

    public function family(?string $mime, ?string $mediaType = null): string
    {
        $mime = strtolower(explode(';', (string) $mime)[0]);
        if (in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/x-7z-compressed', 'application/x-rar-compressed'], true)) return 'archive';
        if (str_starts_with($mime, 'video/') || $mime === 'application/mp4') return 'video';
        if ($mime === 'application/pdf' || $mediaType === 'floorplan') return 'floorplan';
        return 'photo';
    }

    public function accepts(ShootService $item, string $family, string $uploadType = 'edited'): bool
    {
        if (! $this->isWorkItem($item)) return false;
        if ($uploadType === 'raw') return app(UploadIntakeResolver::class)->supportsLane($item, $family);
        return in_array($family, $this->kinds($item), true);
    }

    /** @param iterable<UploadedFile> $files */
    public function resolve(Shoot $shoot, User $actor, string $type, ?int $scope, iterable $files, ?string $mediaType = null): ?ShootService
    {
        if ($type === 'edited') foreach ($files as $file) {
            if (app(\App\Services\RawThumbnailService::class)->isRawFile($file->getClientOriginalName())) {
                $this->reject('Camera RAW files belong in RAW intake. Upload a finished image for edited delivery.');
            }
        }
        $items = $shoot->serviceItems()->with('service.category')->get();
        if ($items->isEmpty()) {
            if ($scope) $this->reject('Selected service does not belong to this shoot.');
            return null; // Compatibility only for genuinely unbooked legacy media.
        }
        $families = collect($files)->map(fn (UploadedFile $file) => $this->family(
            app(UploadIntakeResolver::class)->detectedMimeType($file), $type === 'raw' ? null : $mediaType
        ))->unique()->all();
        $authorization = app(ShootAuthorizationSupport::class);
        $compatible = fn (ShootService $item) => collect($families)->every(fn ($family) => $this->accepts($item, $family, $type));
        if ($scope) {
            $item = $items->firstWhere('id', $scope);
            if (! $item) $this->reject('Selected service does not belong to this shoot.');
            abort_unless($authorization->canUploadShootMedia($shoot, $actor, $type, $scope)
                && ($type !== 'edited' || $this->canUploadFamilies($shoot, $actor, $item, $families)), 403, 'You can only upload to assigned service items and content lanes.');
            if (! $this->isWorkItem($item)) $this->reject('Cancelled and financial-only services cannot receive work.');
            if (! $compatible($item)) $this->reject('The selected service does not accept this content. Choose a compatible service.');
            return $item;
        }
        $eligible = $items->filter(fn ($item) => $this->isOutstanding($item) && $compatible($item)
            && $authorization->canUploadShootMedia($shoot, $actor, $type, $item->id)
            && ($type !== 'edited' || $this->canUploadFamilies($shoot, $actor, $item, $families)));
        if ($eligible->count() !== 1) $this->reject($eligible->isEmpty()
            ? 'No outstanding assigned service accepts this content. Add or select the correct work service.'
            : 'Select one service for this upload batch.', $eligible->isEmpty() ? 'service_item_not_uploadable' : 'missing_service_item');
        return $eligible->first();
    }

    public function canUploadFamilies(Shoot $shoot, User $actor, ShootService $item, array $families): bool
    {
        $roles = app(\App\Services\RolePermissionService::class)->normalizedUserRoles($actor);
        if ($roles->intersect(['admin', 'superadmin', 'editing_manager'])->isNotEmpty()) return true;
        if (! $roles->contains('editor')) return false;
        $bundled = in_array('photo', $this->kinds($item), true) && in_array('video', $this->kinds($item), true);
        return collect($families)->every(function ($family) use ($shoot, $actor, $item, $bundled) {
            $lane = $family === 'floorplan' ? 'photo' : $family;
            $owner = $bundled && $lane === 'video' ? $item->video_editor_id : $item->editor_id;
            if ($owner && (int) $owner === (int) $actor->id) return true;
            if (! $owner && (int) $shoot->editor_id === (int) $actor->id) return true;
            if (! $bundled && $lane === 'video' && (int) $item->video_editor_id === (int) $actor->id) return true;
            return \App\Models\ShootEditingDispatchItem::where('editor_id', $actor->id)->where('destination', 'human')
                ->whereNotIn('status', ['completed', 'cancelled', 'withdrawn'])
                ->where(fn ($q) => $q->where('shoot_service_id', $item->id)->orWhereNull('shoot_service_id'))
                ->where(fn ($q) => $q->where('lane', $lane)->orWhereNull('lane'))
                ->whereHas('dispatch', fn ($q) => $q->where('shoot_id', $shoot->id)->whereNotIn('status', ['cancelled', 'withdrawn']))->exists();
        });
    }

    private function reject(string $message, string $type = 'service_item_not_uploadable'): never
    {
        \App\Exceptions\UploadTargetValidationException::reject($message, $type);
    }
}
