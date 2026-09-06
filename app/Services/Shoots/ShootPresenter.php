<?php

namespace App\Services\Shoots;

use App\Jobs\GenerateWatermarkedImageJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\IguideDataVisibilityService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShootPresenter
{
    public function __construct(
        protected ShootFileAccessService $fileAccessService,
        protected ShootEditingAssignmentService $editingAssignmentService,
        protected DropboxWorkflowService $dropboxService,
        protected \App\Services\Media\MediaStorage $mediaStorage,
        protected ShootClientContactVisibility $clientContactVisibility,
        protected IguideDataVisibilityService $iguideDataVisibility
    ) {}

    public function transformOperationalShoot(Shoot $shoot, bool $isClientUser): array
    {
        $transformedShoot = $this->transformShoot($shoot);
        $shootArray = $transformedShoot->toArray();
        $servicesArray = $transformedShoot->getAttribute('services_list')
            ?? $transformedShoot->services->pluck('name')->filter()->values()->all();
        $shootArray['services_list'] = $servicesArray;

        if (! isset($shootArray['services']) || ! is_array($shootArray['services'])) {
            $shootArray['services'] = $servicesArray;
        }

        $createdByName = $transformedShoot->getAttribute('created_by_name');
        if ($createdByName) {
            $shootArray['created_by'] = $createdByName;
            $shootArray['createdBy'] = $createdByName;
        }

        $shootArray['cancellation_requested_at'] = $transformedShoot->cancellation_requested_at?->toIso8601String();
        $shootArray['cancellationRequestedAt'] = $transformedShoot->cancellation_requested_at?->toIso8601String();
        $shootArray['cancellation_reason'] = $transformedShoot->cancellation_reason;
        $shootArray['cancellationReason'] = $transformedShoot->cancellation_reason;

        $needsWatermark = $isClientUser
            && ! ($shootArray['bypass_paywall'] ?? false)
            && ! in_array($shootArray['payment_status'] ?? '', ['paid', 'full'], true);

        if (isset($shootArray['files']) && is_array($shootArray['files'])) {
            $serviceUnlockByItemId = collect($shootArray['serviceItems'] ?? $shootArray['service_items'] ?? [])
                ->keyBy(fn ($item) => (string) ($item['shoot_service_id'] ?? $item['shootServiceId'] ?? ''));
            $generatedWatermarkForShoot = false;
            $shootArray['files'] = collect($shootArray['files'])->map(function ($file) use ($transformedShoot, $needsWatermark, $serviceUnlockByItemId, &$generatedWatermarkForShoot) {
                $resolveUrl = fn ($path) => $this->resolveMediaAssetUrl($path);
                $shootServiceId = (string) ($file['shoot_service_id'] ?? $file['shootServiceId'] ?? '');
                $serviceItem = $shootServiceId !== '' ? $serviceUnlockByItemId->get($shootServiceId) : null;
                $fileNeedsWatermark = $needsWatermark
                    && ($shootServiceId === '' || ! ($serviceItem['is_unlocked_for_delivery'] ?? $serviceItem['isUnlockedForDelivery'] ?? false));

                if ($fileNeedsWatermark) {
                    if (! $generatedWatermarkForShoot && ! $this->hasWatermarkedPayload($file)) {
                        $modelFile = $transformedShoot->files->firstWhere('id', $file['id'] ?? null);
                        if ($modelFile instanceof ShootFile) {
                            $this->ensureOperationalWatermarkedPreview($modelFile);
                            $file['watermarked_thumbnail_path'] = $modelFile->watermarked_thumbnail_path;
                            $file['watermarked_web_path'] = $modelFile->watermarked_web_path;
                            $file['watermarked_placeholder_path'] = $modelFile->watermarked_placeholder_path;
                            $generatedWatermarkForShoot = $this->hasWatermarkedPayload($file);
                        }
                    }

                    $thumbUrl = $resolveUrl($file['watermarked_thumbnail_path'] ?? null);
                    $webUrl = $resolveUrl($file['watermarked_web_path'] ?? null);
                    $placeholderUrl = $resolveUrl($file['watermarked_placeholder_path'] ?? null);

                    $file['thumbnail_url'] = $thumbUrl;
                    $file['thumb_url'] = $thumbUrl;
                    $file['thumb'] = $thumbUrl;
                    // Watermarked media has no separate grid rendition; the
                    // watermarked web image is the sharpest thing we may serve.
                    $file['grid_url'] = $webUrl;
                    $file['web_url'] = $webUrl;
                    $file['medium_url'] = $webUrl;
                    $file['medium'] = $webUrl;
                    $file['large_url'] = $webUrl;
                    $file['large'] = $webUrl;
                    $file['placeholder_url'] = $placeholderUrl;
                    $file['original_url'] = $webUrl;
                    $file['original'] = $webUrl;
                    $file['url'] = $webUrl;
                    $file['thumbnail_path'] = null;
                    $file['web_path'] = null;
                    $file['placeholder_path'] = null;
                    $file['path'] = null;
                    $file['uses_watermark'] = true;
                    $file['watermarked_thumbnail_path'] = $thumbUrl;
                    $file['watermarked_web_path'] = $webUrl;
                    $file['watermarked_placeholder_path'] = $placeholderUrl;
                } else {
                    $thumbUrl = $resolveUrl($file['thumbnail_path'] ?? null);
                    $webUrl = $resolveUrl($file['web_path'] ?? null);
                    $placeholderUrl = $resolveUrl($file['placeholder_path'] ?? null);
                    // The 600px grid rendition is what shoot cards and grids
                    // display. Without it on this payload they fell back to the
                    // 300px thumbnail and upscaled it into a 256px-tall cover.
                    $gridUrl = $resolveUrl($file['grid_path'] ?? null) ?: $webUrl;

                    $file['thumbnail_url'] = $thumbUrl;
                    $file['thumb_url'] = $thumbUrl;
                    $file['thumb'] = $thumbUrl;
                    $file['grid_url'] = $gridUrl;
                    $file['web_url'] = $webUrl;
                    $file['medium_url'] = $webUrl;
                    $file['medium'] = $webUrl;
                    $file['large_url'] = $webUrl;
                    $file['large'] = $webUrl;
                    $file['placeholder_url'] = $placeholderUrl;
                }

                return $file;
            })->toArray();
        }

        if ($needsWatermark) {
            $watermarkedHeroImage = collect($shootArray['files'] ?? [])
                ->map(fn ($file) => $file['web_url'] ?? $file['thumbnail_url'] ?? $file['thumb_url'] ?? $file['url'] ?? null)
                ->filter()
                ->first();

            $shootArray['hero_image'] = $watermarkedHeroImage;
            $shootArray['heroImage'] = $watermarkedHeroImage;
        }

        return $shootArray;
    }

    protected function resolveMediaAssetUrl($path): ?string
    {
        if (! $path || ! is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $clean = ltrim($path, '/');
        if (Str::startsWith($clean, 'storage/')) {
            $clean = Str::after($clean, 'storage/');
        }

        // Resolve to the R2 CDN once reads are flipped (presenter assets are
        // previews/delivered media), falling back to local then Dropbox.
        if ($this->mediaStorage->readFromR2Enabled() || $this->mediaStorage->r2Only()) {
            if ($this->mediaStorage->existsOnR2($clean)) {
                return $this->fileAccessService->resolvePublicStorageUrl($clean);
            }
            if ($this->mediaStorage->r2Only()) {
                return null;
            }
        }

        if (Storage::disk('public')->exists($clean)) {
            return $this->fileAccessService->resolvePublicStorageUrl($clean);
        }

        try {
            return $this->dropboxService->getTemporaryLink($path);
        } catch (\Exception $e) {
            Log::warning('Failed to resolve shoot media asset URL', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function hasWatermarkedPayload(array $file): bool
    {
        return (bool) (
            ($file['watermarked_web_path'] ?? null)
            || ($file['watermarked_thumbnail_path'] ?? null)
            || ($file['watermarked_placeholder_path'] ?? null)
        );
    }

    protected function ensureOperationalWatermarkedPreview(ShootFile $file): void
    {
        if (
            $this->hasWatermarkedModelPreview($file)
            || ! $file->shouldBeWatermarked()
            || ! $this->canGenerateOperationalWatermarkedPreview($file)
        ) {
            return;
        }

        try {
            $freshFile = $file->fresh();
            if (! $freshFile) {
                return;
            }

            $watermarkJob = new GenerateWatermarkedImageJob($freshFile);
            $watermarkJob->handle($this->dropboxService);
            $file->refresh();
        } catch (\Throwable $e) {
            Log::warning('Failed to generate watermark synchronously for shoot listing preview', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function hasWatermarkedModelPreview(ShootFile $file): bool
    {
        return (bool) (
            $file->watermarked_web_path
            || $file->watermarked_thumbnail_path
            || $file->watermarked_placeholder_path
        );
    }

    protected function canGenerateOperationalWatermarkedPreview(ShootFile $file): bool
    {
        $mediaType = strtolower((string) ($file->media_type ?? ''));
        if (in_array($mediaType, ['image', 'photo', 'edited'], true)) {
            return true;
        }

        $mimeType = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        if (Str::startsWith($mimeType, 'image/')) {
            return true;
        }

        $filename = strtolower((string) ($file->filename ?? $file->stored_filename ?? $file->path ?? ''));

        return (bool) preg_match('/\.(jpg|jpeg|png|webp|gif|tif|tiff|heic|heif)$/i', $filename);
    }

    public function transformShoot(Shoot $shoot): Shoot
    {
        $shoot->loadMissing(['client', 'photographer', 'editor', 'service', 'services.category', 'rep', 'createdByUser', 'ghostUsers', 'featuredHomepageImages.file']);
        if (! $shoot->relationLoaded('files')) {
            $shoot->load(['files' => function ($query) {
                $query->select(
                    'id',
                    'shoot_id',
                    'shoot_service_id',
                    'filename',
                    'stored_filename',
                    'workflow_stage',
                    'is_favorite',
                    'is_cover',
                    'is_hidden',
                    'flag_reason',
                    'url',
                    'path',
                    'dropbox_path',
                    'file_type',
                    'mime_type',
                    'media_type',
                    'thumbnail_path',
                    // Needed for grid_url; see the note in ShootListingService.
                    'grid_path',
                    'web_path',
                    'placeholder_path',
                    'watermarked_storage_path',
                    'watermarked_thumbnail_path',
                    'watermarked_web_path',
                    'watermarked_placeholder_path'
                );
            }]);
        }
        if (! $shoot->relationLoaded('payments')) {
            // Refunds are eager-loaded with payments: the paid total subtracts them,
            // and without this each payment would issue its own query for them.
            $shoot->load('payments.refunds');
        } elseif (! $shoot->payments->every(fn ($payment) => $payment->relationLoaded('refunds'))) {
            $shoot->load('payments.refunds');
        }
        $shoot->append('total_paid', 'remaining_balance', 'total_photographer_pay');
        $requestingUser = auth()->user();
        // Filter before deriving hero images, counters, or serializing loaded files.
        if ($requestingUser) {
            $authorization = app(ShootAuthorizationSupport::class);
            $shoot->setRelation('files', $shoot->files->filter(fn (ShootFile $file) =>
                $authorization->canInteractWithShootMediaFile($shoot, $file, $requestingUser))->values());
        }
        $scopedMediaCounts = app(ShootAuthorizationSupport::class)->scopedMediaCounts($shoot, $requestingUser);
        $visibleIguideData = $this->iguideDataVisibility->forUser($shoot->iguide_data, $requestingUser);
        $rawOfflinePackage = data_get($shoot->iguide_data, 'manual_offline_package');
        $visibleOfflinePackage = $this->iguideDataVisibility->canManage($requestingUser)
            && is_array($rawOfflinePackage)
                ? $rawOfflinePackage
                : $this->iguideDataVisibility->safePackage($rawOfflinePackage);
        $shoot->setAttribute('iguide_data', $visibleIguideData);
        $shoot->setAttribute('iguide_manual_offline_package', $visibleOfflinePackage);
        if (! $this->iguideDataVisibility->canManage($requestingUser)) {
            $shoot->makeHidden(['iguide_property_id', 'iguide_work_order_id']);
        }

        if ($shoot->isComplimentaryReshoot()) {
            $shoot->payment_status = Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED;
        } elseif ((float) ($shoot->total_quote ?? 0) <= 0.01 || ! $shoot->payment_status || ! in_array($shoot->payment_status, ['paid', 'unpaid', 'partial'], true)) {
            $totalPaid = $shoot->total_paid ?? 0;
            $totalQuote = $shoot->total_quote ?? 0;
            $shoot->payment_status = $this->calculatePaymentStatus($totalPaid, $totalQuote);
        }

        $serviceSubtotal = (float) $shoot->services->sum(function ($service) {
            $price = (float) ($service->pivot?->price ?? $service->price ?? 0);
            $quantity = max((int) ($service->pivot?->quantity ?? 1), 1);

            return $price * $quantity;
        });
        $shoot->setAttribute('service_subtotal', round($serviceSubtotal, 2));
        $shoot->setAttribute('serviceSubtotal', round($serviceSubtotal, 2));
        $shoot->setAttribute('discount_type', $shoot->discount_type);
        $shoot->setAttribute('discount_value', $shoot->discount_value !== null ? (float) $shoot->discount_value : null);
        $shoot->setAttribute('discount_amount', (float) ($shoot->discount_amount ?? 0));
        $shoot->setAttribute('discounted_subtotal', (float) ($shoot->base_quote ?? 0));

        $createdByName = 'Unknown';
        if ($shoot->created_by) {
            $createdByUser = $shoot->createdByUser ?? User::find($shoot->created_by);
            if ($createdByUser) {
                $createdByName = $createdByUser->role === 'superadmin'
                    ? 'superadmin'
                    : ($createdByUser->name ?? 'Unknown');
            } else {
                if ($shoot->rep_id && $shoot->rep) {
                    $createdByName = $shoot->rep->name ?? 'Unknown';
                } elseif ($shoot->client_id && $shoot->client) {
                    $createdByName = $shoot->client->name ?? 'Unknown';
                } elseif ($shoot->photographer_id && $shoot->photographer) {
                    $createdByName = $shoot->photographer->name ?? 'Unknown';
                }
            }
        } else {
            if ($shoot->rep_id && $shoot->rep) {
                $createdByName = $shoot->rep->name ?? 'Unknown';
            } elseif ($shoot->client_id && $shoot->client) {
                $createdByName = $shoot->client->name ?? 'Unknown';
            } elseif ($shoot->photographer_id && $shoot->photographer) {
                $createdByName = $shoot->photographer->name ?? 'Unknown';
            }
        }
        $shoot->setAttribute('created_by_name', $createdByName);

        $requestingUser = auth()->user();
        $requestingRole = $requestingUser ? strtolower($requestingUser->role ?? '') : '';
        $isPhotographerRole = $requestingRole === 'photographer';
        $isEditorRole = $requestingRole === 'editor';
        $isClientRole = $requestingRole === 'client';
        $canManageReshoots = in_array($requestingRole, ['admin', 'superadmin'], true);
        $requestingUserId = $requestingUser?->id ? (string) $requestingUser->id : null;
        $editorAssignments = $this->editingAssignmentService->buildEditorAssignmentsPayload(
            $shoot,
            $isEditorRole ? $requestingUser : null
        );
        $serviceItemSummaries = app(ShootServiceItemSupport::class)->summaries($shoot);

        if ($isClientRole && $shoot->photographer) {
            $shoot->setAttribute('photographer', [
                'id' => $shoot->photographer->id,
                'name' => $shoot->photographer->name,
                'avatar' => $shoot->photographer->avatar ?? null,
            ]);
            $shoot->unsetRelation('photographer');
        }

        if ($shoot->client && ! $isEditorRole) {
            if ($isPhotographerRole) {
                // Reachable only around the appointment: two hours before the
                // start, through the on-site buffer, plus two hours after it.
                $clientPhone = $this->clientContactVisibility->phoneFor($shoot, $requestingUser);
                $clientData = [
                    'id' => $shoot->client->id,
                    'name' => $shoot->client->name,
                    'email_verified' => $shoot->client->email_verified_at !== null,
                    'emailVerified' => $shoot->client->email_verified_at !== null,
                    'phone' => $clientPhone,
                    'phonenumber' => $clientPhone,
                ];
            } else {
                $clientPhone = $shoot->client->phonenumber ?? $shoot->client->phone ?? null;
                $clientData = [
                    'id' => $shoot->client->id,
                    'name' => $shoot->client->name,
                    'email' => $shoot->client->email,
                    'email_verified' => $shoot->client->email_verified_at !== null,
                    'emailVerified' => $shoot->client->email_verified_at !== null,
                    'company_name' => $shoot->client->company_name ?? $shoot->client->company ?? null,
                    'phone' => $clientPhone,
                    'phonenumber' => $clientPhone,
                ];

                $clientMetadata = $shoot->client->metadata ?? [];
                $clientRepId = $clientMetadata['accountRepId']
                    ?? $clientMetadata['account_rep_id']
                    ?? $clientMetadata['repId']
                    ?? $clientMetadata['rep_id']
                    ?? $shoot->client->created_by_id
                    ?? null;

                $clientRep = null;
                if ($clientRepId) {
                    $clientRep = User::find($clientRepId);
                }

                if (! $clientRep && $shoot->rep) {
                    $clientRep = $shoot->rep;
                }

                if ($clientRep) {
                    $clientData['rep'] = [
                        'id' => $clientRep->id,
                        'name' => $clientRep->name,
                        'email' => $clientRep->email,
                    ];
                }
            }

            $shoot->setAttribute('client', $clientData);
            $shoot->unsetRelation('client');
        } elseif ($isEditorRole) {
            $shoot->setAttribute('client', null);
            $shoot->unsetRelation('client');
        }

        if ($isEditorRole) {
            $shoot->setAttribute('photographer', null);
            $shoot->photographer_id = null;
            $shoot->resolved_photographer_id = null;
            $shoot->resolved_photographer_name = null;
            $shoot->photographer_name = null;
            $shoot->payment_status = null;
            $shoot->total_paid = null;
            $shoot->remaining_balance = null;
            $shoot->shoot_notes = null;
            $shoot->company_notes = null;
            $shoot->photographer_notes = null;
        }

        $ghostUsers = collect($shoot->ghostUsers ?? [])
            ->map(function ($ghostUser) {
                if (is_array($ghostUser)) {
                    return [
                        'id' => isset($ghostUser['id']) ? (string) $ghostUser['id'] : null,
                        'name' => $ghostUser['name'] ?? 'Client',
                        'email' => $ghostUser['email'] ?? null,
                        'company' => $ghostUser['company'] ?? ($ghostUser['company_name'] ?? null),
                    ];
                }

                return [
                    'id' => isset($ghostUser->id) ? (string) $ghostUser->id : null,
                    'name' => $ghostUser->name ?? 'Client',
                    'email' => $ghostUser->email ?? null,
                    'company' => $ghostUser->company_name ?? $ghostUser->company ?? null,
                ];
            })
            ->filter(fn ($ghostUser) => ! empty($ghostUser['id']))
            ->values();
        $ghostUserIds = $ghostUsers->pluck('id')->values()->all();
        $isDeliveredForGhostAccess = in_array(strtolower((string) ($shoot->workflow_status ?: $shoot->status ?: '')), [
            Shoot::STATUS_DELIVERED,
            'ready_for_client',
            'admin_verified',
            'ready',
            'workflow_completed',
            'client_delivered',
        ], true);
        $isGhostVisibleForUser = $isClientRole
            && $isDeliveredForGhostAccess
            && $requestingUserId !== null
            && (string) $shoot->client_id !== $requestingUserId
            && in_array($requestingUserId, $ghostUserIds, true);

        $shoot->setAttribute('ghost_user_ids', $ghostUserIds);
        $shoot->setAttribute('ghost_users', $ghostUsers->all());
        $shoot->setAttribute('is_ghost_visible_for_user', $isGhostVisibleForUser);
        $shoot->unsetRelation('ghostUsers');

        $tourLinks = is_array($shoot->tour_links) ? $shoot->tour_links : [];
        $realtorClient = $this->resolveRealtorClient($tourLinks);
        if ($realtorClient) {
            $tourLinks['realtor_client'] = $realtorClient;
        }
        $shoot->setAttribute('realtor_client', $realtorClient);

        $shoot->package = [
            'name' => $shoot->package_name ?? optional($shoot->service)->name,
            'expectedDeliveredCount' => $shoot->expected_final_count,
            'bracketMode' => $shoot->bracket_mode,
            'servicesIncluded' => ! empty($shoot->package_services_included)
                ? $shoot->package_services_included
                : $shoot->services->pluck('name')->toArray(),
        ];

        $shoot->weather = [
            'summary' => $shoot->weather_summary,
            'temperature' => $shoot->weather_temperature,
        ];

        $shoot->dropbox_paths = [
            'rawFolder' => $shoot->dropbox_raw_folder,
            'extraFolder' => $shoot->dropbox_extra_folder,
            'editedFolder' => $shoot->dropbox_edited_folder,
            'archiveFolder' => $shoot->dropbox_archive_folder,
        ];

        $shoot->media_summary = $this->buildMediaSummary($shoot);
        if (! $shoot->hero_image || in_array($requestingRole, ['photographer', 'editor', 'client'], true)) {
            $shoot->hero_image = $this->resolveHeroImage($shoot, false);
        }
        $shoot->primary_action = $this->getPrimaryActionForRole(
            $shoot,
            auth()->user()->role ?? 'client'
        );

        $shoot->shoot_notes = ($isEditorRole || $isGhostVisibleForUser) ? null : $shoot->shoot_notes;
        $shoot->company_notes = ($isEditorRole || $isClientRole) ? null : $shoot->company_notes;
        $shoot->photographer_notes = ($isEditorRole || $isClientRole) ? null : $shoot->photographer_notes;
        $shoot->editor_notes = $isClientRole ? null : $shoot->editor_notes;

        // Complimentary-reshoot notes are the internal reason narrative. The
        // affected-work mapping below is admin-only, so do not leak the same
        // narrative through the legacy shoot_notes field to recipients/clients.
        if ($shoot->isComplimentaryReshoot() && ! $canManageReshoots) {
            $shoot->shoot_notes = null;
            $shoot->makeHidden('shoot_notes');
        }

        if ($isClientRole) {
            $shoot->makeHidden([
                'company_notes',
                'photographer_notes',
                'editor_notes',
            ]);
        }

        $shoot->mls_id = $shoot->mls_id;
        $shoot->mls_image_width = $shoot->mls_image_width;
        $shoot->mlsImageWidth = $shoot->mls_image_width;
        $shoot->timezone = $shoot->timezone;
        $shoot->listing_source = $shoot->listing_source;
        $shoot->property_details = $shoot->property_details;
        $shoot->integration_flags = $shoot->integration_flags;
        $shoot->bright_mls_publish_status = $shoot->bright_mls_publish_status;
        $shoot->bright_mls_last_published_at = $shoot->bright_mls_last_published_at;
        $shoot->bright_mls_manifest_id = $shoot->bright_mls_manifest_id;
        $shoot->iguide_tour_url = $shoot->iguide_tour_url;
        $shoot->iguide_floorplans = $shoot->iguide_floorplans;
        $shoot->iguide_last_synced_at = $shoot->iguide_last_synced_at;
        $shoot->cubicasa_order_id = $shoot->cubicasa_order_id;
        $shoot->cubicasaOrderId = $shoot->cubicasa_order_id;
        $shoot->cubicasa_external_id = $shoot->cubicasa_external_id;
        $shoot->cubicasaExternalId = $shoot->cubicasa_external_id;
        $shoot->cubicasa_status = $shoot->cubicasa_status;
        $shoot->cubicasaStatus = $shoot->cubicasa_status;
        $shoot->cubicasa_product_type = $shoot->cubicasa_product_type;
        $shoot->cubicasaProductType = $shoot->cubicasa_product_type;
        $shoot->cubicasa_tour_url = $shoot->cubicasa_tour_url;
        $shoot->cubicasaTourUrl = $shoot->cubicasa_tour_url;
        $shoot->cubicasa_floorplans = $shoot->cubicasa_floorplans ?? [];
        $shoot->cubicasaFloorplans = $shoot->cubicasa_floorplans;
        $shoot->cubicasa_last_synced_at = $shoot->cubicasa_last_synced_at?->toIso8601String();
        $shoot->cubicasaLastSyncedAt = $shoot->cubicasa_last_synced_at;
        $shoot->cubicasa_sync_status = $shoot->cubicasa_sync_status;
        $shoot->cubicasaSyncStatus = $shoot->cubicasa_sync_status;
        $shoot->cubicasa_last_sync_error = $shoot->cubicasa_last_sync_error;
        $shoot->cubicasaLastSyncError = $shoot->cubicasa_last_sync_error;
        $shoot->is_private_listing = $shoot->is_private_listing ?? false;
        $shoot->is_featured = $shoot->is_featured ?? false;
        $shoot->isFeatured = (bool) ($shoot->is_featured ?? false);
        $shoot->featured_pending = ! ($shoot->is_featured ?? false) && ! empty($shoot->featured_requested_at);
        $shoot->featuredPending = (bool) $shoot->featured_pending;
        $shoot->featured_status = $shoot->isFeatured
            ? 'featured'
            : ($shoot->featuredPending ? 'pending' : 'none');
        $shoot->featuredStatus = $shoot->featured_status;
        $shoot->featured_requested_at = $shoot->featured_requested_at?->toIso8601String();
        $shoot->featuredRequestedAt = $shoot->featured_requested_at;
        $shoot->featured_requested_by = $shoot->featured_requested_by;
        $shoot->featuredRequestedBy = $shoot->featured_requested_by;
        $shoot->featured_approved_at = $shoot->featured_approved_at?->toIso8601String();
        $shoot->featuredApprovedAt = $shoot->featured_approved_at;
        $shoot->featured_approved_by = $shoot->featured_approved_by;
        $shoot->featuredApprovedBy = $shoot->featured_approved_by;
        $shoot->is_listing_hidden = $shoot->is_listing_hidden ?? false;
        $shoot->isListingHidden = (bool) ($shoot->is_listing_hidden ?? false);
        $shoot->mmm_status = $shoot->mmm_status;
        $shoot->mmm_order_number = $shoot->mmm_order_number;
        $shoot->mmm_buyer_cookie = $shoot->mmm_buyer_cookie;
        $shoot->mmm_redirect_url = $shoot->mmm_redirect_url;
        $shoot->mmm_last_punchout_at = $shoot->mmm_last_punchout_at;
        $shoot->mmm_last_order_at = $shoot->mmm_last_order_at;
        $shoot->mmm_last_error = $shoot->mmm_last_error;
        $shoot->tour_links = $tourLinks;
        $shoot->setAttribute('editor_assignments', $editorAssignments);
        $shoot->setAttribute('editorAssignments', $editorAssignments);
        $shoot->setAttribute('deliveryStatus', $shoot->delivery_status ?? 'not_started');

        try {
            if ($shoot->relationLoaded('services') && $shoot->services->isNotEmpty()) {
                $servicesSource = collect($shoot->services);
                if ($isEditorRole && $requestingUser) {
                    $servicesSource = $this->editingAssignmentService->filterServicesForEditor($shoot, $requestingUser);
                    $visibleServiceIds = collect($servicesSource)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $serviceItemSummaries = collect($serviceItemSummaries)
                        ->filter(fn ($item) => in_array((int) ($item['service_id'] ?? 0), $visibleServiceIds, true))
                        ->values()
                        ->all();
                }
                if ($isPhotographerRole && $requestingUser) {
                    $photographerUserId = (string) $requestingUser->id;
                    $isTopLevelPhotographer = (string) $shoot->photographer_id === $photographerUserId;
                    $servicesSource = collect($servicesSource)
                        ->filter(function ($service) use ($photographerUserId, $isTopLevelPhotographer) {
                            if (! is_object($service)) {
                                return true;
                            }

                            $servicePhotographerId = $service->pivot?->photographer_id;

                            return $servicePhotographerId
                                ? (string) $servicePhotographerId === $photographerUserId
                                : $isTopLevelPhotographer;
                        })
                        ->values();
                    $visibleServiceIds = collect($servicesSource)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $serviceItemSummaries = collect($serviceItemSummaries)
                        ->filter(fn ($item) => in_array((int) ($item['service_id'] ?? 0), $visibleServiceIds, true))
                        ->values()
                        ->all();
                }
                $serviceItemByServiceId = collect($serviceItemSummaries)->keyBy('service_id');

                $shootPhotographerId = $shoot->photographer_id;
                $shootPhotographer = $shoot->relationLoaded('photographer')
                    ? $shoot->getRelation('photographer')
                    : null;
                if (! $shootPhotographer instanceof User) {
                    $shootPhotographer = null;
                }

                $servicePhotographerIds = $servicesSource
                    ->filter(fn ($s) => is_object($s))
                    ->map(fn ($s) => $s->pivot?->photographer_id)
                    ->filter()
                    ->unique()
                    ->values();
                $servicePhotographers = $servicePhotographerIds->isNotEmpty()
                    ? User::whereIn('id', $servicePhotographerIds)->get()->keyBy('id')
                    : collect();
                $serviceEditorIds = $servicesSource
                    ->filter(fn ($s) => is_object($s))
                    ->map(fn ($s) => $s->pivot?->editor_id)
                    ->filter()
                    ->unique()
                    ->values();
                $serviceEditors = $serviceEditorIds->isNotEmpty()
                    ? User::whereIn('id', $serviceEditorIds)->get()->keyBy('id')
                    : collect();

                $transformedServices = $servicesSource->map(function ($service) use (
                    $shootPhotographerId,
                    $shootPhotographer,
                    $servicePhotographers,
                    $serviceEditors,
                    $isEditorRole,
                    $serviceItemByServiceId
                ) {
                    if (is_array($service)) {
                        return $service;
                    }

                    $pivotPhotographerId = $service->pivot?->photographer_id ?? null;
                    $resolvedPhotographerId = $pivotPhotographerId ?? $shootPhotographerId;

                    $resolvedPhotographer = null;
                    if (! $isEditorRole && $resolvedPhotographerId) {
                        $photographer = $pivotPhotographerId
                            ? $servicePhotographers->get($pivotPhotographerId)
                            : $shootPhotographer;
                        if ($photographer) {
                            $resolvedPhotographer = [
                                'id' => (string) $photographer->id,
                                'name' => $photographer->name,
                                'avatar' => $photographer->avatar ?? null,
                            ];
                        }
                    }

                    $categoryObj = $service->relationLoaded('category') ? $service->getRelation('category') : null;
                    $categoryName = $categoryObj?->name ?? $service->category_name ?? $service->name;
                    $lane = $this->editingAssignmentService->normalizeLane($categoryName);
                    $sqftRanges = $service->relationLoaded('sqftRanges')
                        ? $service->getRelation('sqftRanges')
                        : $service->sqftRanges()->get();
                    $pivotEditorId = $service->pivot?->editor_id ?? null;
                    $resolvedEditor = null;
                    if ($pivotEditorId) {
                        $editor = $serviceEditors->get($pivotEditorId);
                        if ($editor) {
                            $resolvedEditor = [
                                'id' => (string) $editor->id,
                                'name' => $editor->name,
                                'avatar' => $editor->avatar ?? null,
                                'email' => $editor->email,
                            ];
                        }
                    }
                    $editingCompletedAt = $service->pivot?->editing_completed_at;
                    $serviceItemSummary = $serviceItemByServiceId->get($service->id, []);

                    return [
                        'id' => (string) $service->id,
                        'shoot_service_id' => isset($serviceItemSummary['shoot_service_id']) ? (string) $serviceItemSummary['shoot_service_id'] : ($service->pivot?->id ? (string) $service->pivot->id : null),
                        'shootServiceId' => isset($serviceItemSummary['shootServiceId']) ? (string) $serviceItemSummary['shootServiceId'] : ($service->pivot?->id ? (string) $service->pivot->id : null),
                        'name' => $service->name,
                        // Editors must never receive client pricing for a service (QA #13);
                        // the editor UI surfaces editor payout separately.
                        'price' => $isEditorRole ? null : (float) ($service->pivot?->price ?? $service->price ?? 0),
                        'quantity' => (int) ($service->pivot?->quantity ?? 1),
                        'pricing_type' => $service->pricing_type,
                        // Canonical contracted photo count. Null means unspecified, and
                        // booking quantity is never substituted for it.
                        'photo_count' => $serviceItemSummary['photo_count']
                            ?? ($service->photo_count !== null && (int) $service->photo_count > 0
                                ? (int) $service->photo_count
                                : null),
                        // Upload capability, carried through from the service item so
                        // the resolver runs once per item. The client builds its photo
                        // and video selectors from these instead of guessing from the
                        // service name, which is how fees, floor plans and tour
                        // products previously became upload targets.
                        'upload_intake_type' => $serviceItemSummary['upload_intake_type'] ?? \App\Models\Service::INTAKE_NONE,
                        'uploadIntakeType' => $serviceItemSummary['uploadIntakeType'] ?? \App\Models\Service::INTAKE_NONE,
                        'supports_photo_intake' => $serviceItemSummary['supports_photo_intake'] ?? false,
                        'supportsPhotoIntake' => $serviceItemSummary['supportsPhotoIntake'] ?? false,
                        'supports_video_intake' => $serviceItemSummary['supports_video_intake'] ?? false,
                        'supportsVideoIntake' => $serviceItemSummary['supportsVideoIntake'] ?? false,
                        // Per-service bracket state. The upload UI needs all three:
                        // whether to show a bracket control at all, what it is
                        // currently set to, and what stacking will actually use if
                        // nothing was pinned.
                        'uses_hdr_brackets' => $serviceItemSummary['uses_hdr_brackets'] ?? (bool) ($service->uses_hdr_brackets ?? false),
                        'usesHdrBrackets' => $serviceItemSummary['usesHdrBrackets'] ?? (bool) ($service->uses_hdr_brackets ?? false),
                        'bracket_mode' => $serviceItemSummary['bracket_mode'] ?? ($service->pivot?->bracket_mode ?? null),
                        'bracketMode' => $serviceItemSummary['bracketMode'] ?? ($service->pivot?->bracket_mode ?? null),
                        'effective_bracket_mode' => $serviceItemSummary['effective_bracket_mode'] ?? null,
                        'effectiveBracketMode' => $serviceItemSummary['effectiveBracketMode'] ?? null,
                        'expected_raw_count' => $serviceItemSummary['expected_raw_count'] ?? null,
                        'expectedRawCount' => $serviceItemSummary['expectedRawCount'] ?? null,
                        'expected_raw_unspecified' => $serviceItemSummary['expected_raw_unspecified'] ?? false,
                        'expectedRawUnspecified' => $serviceItemSummary['expectedRawUnspecified'] ?? false,
                        'sqft_ranges' => $sqftRanges->map(fn ($range) => [
                            'id' => $range->id,
                            'sqft_from' => (int) $range->sqft_from,
                            'sqft_to' => (int) $range->sqft_to,
                            'duration' => $range->duration !== null ? (int) $range->duration : null,
                            'price' => (float) $range->price,
                            'photographer_pay' => $range->photographer_pay !== null ? (float) $range->photographer_pay : null,
                            'photo_count' => $range->photo_count !== null ? (int) $range->photo_count : null,
                        ])->values()->all(),
                        'photographer_pay' => $isEditorRole ? null : ($service->pivot?->photographer_pay ? (float) $service->pivot->photographer_pay : null),
                        'photographer_id' => $isEditorRole ? null : ($pivotPhotographerId ? (string) $pivotPhotographerId : null),
                        'resolved_photographer_id' => $isEditorRole ? null : ($resolvedPhotographerId ? (string) $resolvedPhotographerId : null),
                        'photographer' => $isEditorRole ? null : $resolvedPhotographer,
                        'editor_id' => $pivotEditorId ? (string) $pivotEditorId : null,
                        'editor' => $resolvedEditor,
                        'scheduled_at' => $serviceItemSummary['scheduled_at'] ?? null,
                        'scheduledAt' => $serviceItemSummary['scheduledAt'] ?? null,
                        'workflow_status' => $serviceItemSummary['workflow_status'] ?? ($service->pivot?->workflow_status ?? null),
                        'workflowStatus' => $serviceItemSummary['workflowStatus'] ?? ($service->pivot?->workflow_status ?? null),
                        'delivery_status' => $serviceItemSummary['delivery_status'] ?? ($service->pivot?->delivery_status ?? null),
                        'deliveryStatus' => $serviceItemSummary['deliveryStatus'] ?? ($service->pivot?->delivery_status ?? null),
                        'ready_at' => $serviceItemSummary['ready_at'] ?? null,
                        'readyAt' => $serviceItemSummary['readyAt'] ?? null,
                        'delivered_at' => $serviceItemSummary['delivered_at'] ?? null,
                        'deliveredAt' => $serviceItemSummary['deliveredAt'] ?? null,
                        'is_deliverable' => $serviceItemSummary['is_deliverable'] ?? true,
                        'isDeliverable' => $serviceItemSummary['isDeliverable'] ?? true,
                        'paid_amount' => $isEditorRole ? null : ($serviceItemSummary['paid_amount'] ?? 0.0),
                        'paidAmount' => $isEditorRole ? null : ($serviceItemSummary['paidAmount'] ?? 0.0),
                        'balance_due' => $isEditorRole ? null : ($serviceItemSummary['balance_due'] ?? (float) ($service->pivot?->price ?? $service->price ?? 0)),
                        'balanceDue' => $isEditorRole ? null : ($serviceItemSummary['balanceDue'] ?? (float) ($service->pivot?->price ?? $service->price ?? 0)),
                        'payment_status' => $isEditorRole ? null : ($serviceItemSummary['payment_status'] ?? 'unpaid'),
                        'paymentStatus' => $isEditorRole ? null : ($serviceItemSummary['paymentStatus'] ?? 'unpaid'),
                        'force_unlock_delivery' => $serviceItemSummary['force_unlock_delivery'] ?? false,
                        'forceUnlockDelivery' => $serviceItemSummary['forceUnlockDelivery'] ?? false,
                        'is_unlocked_for_delivery' => $serviceItemSummary['is_unlocked_for_delivery'] ?? false,
                        'isUnlockedForDelivery' => $serviceItemSummary['isUnlockedForDelivery'] ?? false,
                        'unlock_state' => $serviceItemSummary['unlock_state'] ?? 'locked',
                        'unlockState' => $serviceItemSummary['unlockState'] ?? 'locked',
                        'editing_completed_at' => $editingCompletedAt instanceof \DateTimeInterface
                            ? $editingCompletedAt->format(\DateTimeInterface::ATOM)
                            : ($editingCompletedAt ? (string) $editingCompletedAt : null),
                        'lane' => $lane,
                        'category_key' => $lane,
                        'category' => $categoryObj ? [
                            'id' => (string) $categoryObj->id,
                            'name' => $categoryObj->name,
                        ] : null,
                        'category_name' => $categoryObj?->name,
                    ];
                })->values()->all();

                $shoot->setRelation('services', collect($transformedServices));
                $shoot->setAttribute('services', $transformedServices);
            }
        } catch (\Throwable $e) {
            \Log::warning('transformShoot services error for shoot '.$shoot->id.': '.$e->getMessage());
        }

        // Invoice adjustments are client/admin billing rows, not operational
        // assignments for editors or photographers.
        if ($isEditorRole || $isPhotographerRole) {
            $serviceItemSummaries = collect($serviceItemSummaries)
                ->reject(fn ($item) => (bool) ($item['is_invoice_adjustment'] ?? false))
                ->values()
                ->all();
        }

        // Editors must never receive client pricing — strip it from the
        // service-item summaries that are also exposed as serviceItems/service_items
        // (QA #13), mirroring the per-service strip applied above.
        if ($isEditorRole) {
            $pricingKeysToHide = [
                'price', 'subtotal', 'paid_amount', 'paidAmount',
                'balance_due', 'balanceDue', 'payment_status', 'paymentStatus',
                'photographer_pay', 'photographerPay',
            ];
            $serviceItemSummaries = collect($serviceItemSummaries)
                ->map(function ($item) use ($pricingKeysToHide) {
                    if (! is_array($item)) {
                        return $item;
                    }
                    foreach ($pricingKeysToHide as $key) {
                        if (array_key_exists($key, $item)) {
                            $item[$key] = null;
                        }
                    }

                    return $item;
                })
                ->values()
                ->all();
        }

        $shoot->setAttribute('serviceItems', $serviceItemSummaries);
        $shoot->setAttribute('service_items', $serviceItemSummaries);
        $shoot->setAttribute('orderItems', $serviceItemSummaries);
        $shoot->setAttribute('order_items', $serviceItemSummaries);
        // Names for every booked service, deliberately not role-filtered. The
        // summaries above are narrowed by workflow eligibility, which left a
        // viewer who may see a file but not edit its service without a name for
        // it. Display-only: pivot id, catalogue id, name.
        $servicePresentation = app(ShootServiceItemSupport::class)->presentation($shoot);
        $shoot->setAttribute('servicePresentation', $servicePresentation);
        $shoot->setAttribute('service_presentation', $servicePresentation);
        $invoiceAdjustmentTotal = collect($serviceItemSummaries)
            ->filter(fn ($item) => (bool) ($item['is_invoice_adjustment'] ?? false))
            ->sum(fn ($item) => (float) ($item['total_amount'] ?? $item['subtotal'] ?? 0));
        $shoot->setAttribute('invoice_adjustments_total', round($invoiceAdjustmentTotal, 2));
        $shoot->setAttribute('invoiceAdjustmentsTotal', round($invoiceAdjustmentTotal, 2));
        $shoot->setAttribute('order_total', (float) ($shoot->total_quote ?? 0));
        $shoot->setAttribute('orderTotal', (float) ($shoot->total_quote ?? 0));

        // Shared workflow capability block. Keep additive snake/camel aliases here;
        // additional server-owned capabilities (for example invoice access) belong
        // beside this block so list/detail payloads remain easy to audit.
        $canSubmitRaw = app(ShootSubmissionCapabilityService::class)
            ->canSubmitRaw($shoot, $requestingUser);
        $shoot->setAttribute('can_submit_raw', $canSubmitRaw);
        $shoot->setAttribute('canSubmitRaw', $canSubmitRaw);
        $canViewInvoice = app(\App\Services\Invoices\InvoiceAuthorizationService::class)
            ->canViewShootInvoice($shoot, $requestingUser);
        $shoot->setAttribute('can_view_invoice', $canViewInvoice);
        $shoot->setAttribute('canViewInvoice', $canViewInvoice);
        $canFinalizeNoMedia = app(NoMediaDeliveryEligibility::class)
            ->allows($shoot, $requestingUser, $serviceItemSummaries);
        $shoot->setAttribute('can_finalize_no_media', $canFinalizeNoMedia);
        $shoot->setAttribute('canFinalizeNoMedia', $canFinalizeNoMedia);
        $canRemoveAllServices = app(ShootServiceChangeGuard::class)
            ->canRemoveAllServices($shoot, $requestingUser);
        $shoot->setAttribute('can_remove_all_services', $canRemoveAllServices);
        $shoot->setAttribute('canRemoveAllServices', $canRemoveAllServices);
        $overpaymentAmount = round(max(
            (float) ($shoot->total_paid ?? 0) - (float) ($shoot->total_quote ?? 0),
            0
        ), 2);
        $shoot->setAttribute('overpayment_amount', $overpaymentAmount);
        $shoot->setAttribute('overpaymentAmount', $overpaymentAmount);

        $servicesArray = collect($shoot->getAttribute('services') ?? $shoot->services)->pluck('name')->filter()->values()->all();
        $miscItems = ($isEditorRole || $isPhotographerRole)
            ? []
            : collect($serviceItemSummaries)
                ->filter(fn ($item) => (bool) ($item['is_invoice_adjustment'] ?? false))
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
        if (! empty($miscItems)) {
            $servicesArray = array_merge($servicesArray, $miscItems);
        }

        $shoot->setAttribute('services_list', $servicesArray);
        $shoot->setAttribute('shoot_type', $shoot->shoot_type ?? Shoot::SHOOT_TYPE_STANDARD);
        $shoot->setAttribute('shootType', $shoot->shoot_type ?? Shoot::SHOOT_TYPE_STANDARD);
        $shoot->setAttribute('reshootOfShootId', $shoot->reshoot_of_shoot_id);
        $shoot->setAttribute('rootShootId', $shoot->root_shoot_id);
        $shoot->setAttribute(
            'reshoot_classification',
            $shoot->reshoot_of_shoot_id
                ? ($shoot->isComplimentaryReshoot() ? 'complimentary_reshoot' : 'additional_work')
                : null
        );
        $shoot->setAttribute('reshootClassification', $shoot->reshoot_classification);
        $isOwningClient = $isClientRole
            && $requestingUser
            && (int) $requestingUser->id === (int) $shoot->client_id;
        $canViewSafeReshootContext = $canManageReshoots || $isOwningClient;
        if ($canViewSafeReshootContext
            && ($shoot->relationLoaded('reshootChildren')
                || $shoot->relationLoaded('rootReshootDescendants')
                || $shoot->relationLoaded('rootShoot'))) {
            $relatedSummaries = collect(
                $canManageReshoots
                    ? $shoot->relatedReshootSummaries()
                    : $shoot->safeRelatedReshootSummaries()
            );
            $reshootSummary = [
                'related_count' => $relatedSummaries->count(),
                'complimentary_count' => $relatedSummaries
                    ->where('classification', 'complimentary_reshoot')
                    ->count(),
                'additional_work_count' => $relatedSummaries
                    ->where('classification', 'additional_work')
                    ->count(),
                'related_reshoots' => $relatedSummaries->all(),
            ];
            $shoot->setAttribute('reshoot_summary', $reshootSummary);
            $shoot->setAttribute('reshootSummary', $reshootSummary);
            $complimentaryReshoots = $relatedSummaries
                ->where('classification', 'complimentary_reshoot')
                ->values()
                ->all();
            $shoot->setAttribute('complimentary_reshoots', $complimentaryReshoots);
            $shoot->setAttribute('complimentaryReshoots', $complimentaryReshoots);
        }
        if ($canViewSafeReshootContext && $shoot->isComplimentaryReshoot()) {
            $lineageContext = $shoot->safeReshootLineageContext();
            $shoot->setAttribute('reshoot_parent', $lineageContext['parent']);
            $shoot->setAttribute('reshootParent', $lineageContext['parent']);
            $shoot->setAttribute('reshoot_root', $lineageContext['root']);
            $shoot->setAttribute('reshootRoot', $lineageContext['root']);
        }
        if ($canManageReshoots && $shoot->isComplimentaryReshoot()) {
            $complimentaryOverview = $shoot->complimentaryReshootOverview();
            $shoot->setAttribute('complimentary_reshoot_overview', $complimentaryOverview);
            $shoot->setAttribute('complimentaryReshootOverview', $complimentaryOverview);
            $shoot->setAttribute('compensation_summary', $complimentaryOverview);
            $shoot->setAttribute('compensationSummary', $complimentaryOverview);
            $reshootServiceLinks = $shoot->complimentaryReshootServiceLinks();
            $shoot->setAttribute('reshoot_service_links', $reshootServiceLinks);
            $shoot->setAttribute('reshootServiceLinks', $reshootServiceLinks);
            $shoot->setAttribute('affected_source_items', $reshootServiceLinks);
            $primaryReshootLink = collect($reshootServiceLinks)->first();
            $shoot->setAttribute('reshoot_reason_code', $primaryReshootLink['reason_code'] ?? null);
            $shoot->setAttribute('reshootReasonCode', $primaryReshootLink['reason_code'] ?? null);
            $shoot->setAttribute('reshoot_reason_note', $primaryReshootLink['reason_note'] ?? null);
            $shoot->setAttribute('reshootReasonNote', $primaryReshootLink['reason_note'] ?? null);
            foreach (['compReshootItems', 'compensations', 'editorPayouts'] as $relation) {
                $shoot->unsetRelation($relation);
            }
        }
        // The eager-loaded self-relations exist only to build the compact,
        // role-gated summaries above. Never serialize the raw nested shoot graph.
        foreach (['reshootChildren', 'rootReshootDescendants', 'rootShoot', 'reshootOf'] as $relation) {
            $shoot->unsetRelation($relation);
        }
        $shoot->setAttribute('product_status', $shoot->product_status ?? Shoot::PRODUCT_STATUS_HAS_PRODUCT);
        $shoot->setAttribute('productStatus', $shoot->product_status ?? Shoot::PRODUCT_STATUS_HAS_PRODUCT);
        $shoot->setAttribute('raw_photo_count', $shoot->raw_photo_count ?? 0);
        $shoot->setAttribute('edited_photo_count', $shoot->edited_photo_count ?? 0);
        $shoot->setAttribute('raw_missing_count', $shoot->raw_missing_count ?? 0);
        $shoot->setAttribute('edited_missing_count', $shoot->edited_missing_count ?? 0);
        // Derived from the service items rather than the stored column, which was
        // expected_final_count x bracket_mode and therefore 0 on every shoot. The
        // sum also covers services captured at different bracket sizes.
        $shoot->setAttribute(
            'expected_raw_count',
            app(BracketModeResolver::class)->expectedRawForShoot($shoot)
        );
        // The sum only covers items whose contracted count is configured. When some
        // booked item owes photos with no count, the total is a floor and has to be
        // labelled as such rather than presented as exact.
        $expectedRawIsExact = app(BracketModeResolver::class)->expectedRawIsExactForShoot($shoot);
        $shoot->setAttribute('expected_raw_count_is_exact', $expectedRawIsExact);
        $shoot->setAttribute('expectedRawCountIsExact', $expectedRawIsExact);
        $shoot->setAttribute('missing_raw', (bool) $shoot->missing_raw);
        $shoot->setAttribute('missing_final', (bool) $shoot->missing_final);

        if (! empty($editorAssignments)) {
            if ($isEditorRole) {
                $currentEditorAssignment = collect($editorAssignments)->first();
                $shoot->editor_id = $currentEditorAssignment['editor_id'] ?? $requestingUserId;
                $shoot->setAttribute('editor', $currentEditorAssignment['editor'] ?? [
                    'id' => $requestingUserId,
                    'name' => $requestingUser?->name,
                    'avatar' => $requestingUser?->avatar ?? null,
                    'email' => $requestingUser?->email ?? null,
                ]);
            } elseif (count($editorAssignments) === 1) {
                $shoot->editor_id = $editorAssignments[0]['editor_id'] ?? $shoot->editor_id;
                $shoot->setAttribute('editor', $editorAssignments[0]['editor'] ?? $shoot->editor);
            }
        }

        // Ordinary client/contractor shoot payloads must never serialize full
        // User models from eager-loaded audit relationships. Those models carry
        // email-health, verification timestamps, account metadata, and other
        // admin-only diagnostics. The safe client/photographer/editor summaries
        // above remain authoritative for the UI.
        if (! in_array($requestingRole, ['admin', 'superadmin', 'editing_manager'], true)) {
            foreach (['rep', 'createdByUser', 'verifiedBy', 'workflowLogs'] as $sensitiveRelation) {
                $shoot->unsetRelation($sensitiveRelation);
            }
        }

        // Compute workflow capabilities from the original operational counters;
        // only the returned counters are narrowed to this viewer's assignments.
        foreach ($scopedMediaCounts ?? [] as $field => $value) {
            $shoot->setAttribute($field, $value);
        }
        $shoot->media_summary = $this->buildMediaSummary($shoot);

        return $shoot;
    }

    public function formatFullAddress(Shoot $shoot): string
    {
        return trim(sprintf(
            '%s, %s, %s %s',
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip
        ), ', ');
    }

    protected function resolveRealtorClient(array $tourLinks): ?array
    {
        $realtorClientId = $tourLinks['realtor_client_id'] ?? $tourLinks['realtorClientId'] ?? null;
        if (! $realtorClientId) {
            return null;
        }

        $client = User::query()
            ->where('role', 'client')
            ->find($realtorClientId);

        if (! $client) {
            return null;
        }

        return [
            'id' => (string) $client->id,
            'name' => $client->name ?? 'Client',
            'email' => $client->email ?? null,
            'company' => $client->company_name ?? $client->company ?? null,
        ];
    }

    protected function buildMediaSummary(Shoot $shoot): array
    {
        return [
            'rawUploaded' => $shoot->raw_photo_count ?? 0,
            'editedUploaded' => $shoot->edited_photo_count ?? 0,
            'extraUploaded' => $shoot->extra_photo_count ?? 0,
            'flagged' => $shoot->files->whereNotNull('flag_reason')->count(),
            'favorites' => $shoot->files->where('is_favorite', true)->count(),
            'delivered' => $shoot->files->where('workflow_stage', ShootFile::STAGE_VERIFIED)->count(),
        ];
    }

    protected function resolveHeroImage(Shoot $shoot, bool $allowDropboxCalls = true): ?string
    {
        $isExcluded = function ($file) {
            if ($file->is_hidden) {
                return true;
            }
            // Floor plans are classified at ingest (media_type = 'floorplan');
            // every other surface keys off that flag, so the hero image does too
            // instead of re-guessing from the filename at read time.
            $mediaType = strtolower($file->media_type ?? '');
            if ($mediaType === 'floorplan') {
                return true;
            }

            return false;
        };

        $cover = $shoot->files->firstWhere('is_cover', true);
        if ($cover && ! $isExcluded($cover)) {
            return $this->resolveOptimizedFileUrl($cover);
        }

        $withOptimized = $shoot->files->first(function ($file) use ($isExcluded) {
            return ! $isExcluded($file) && (! empty($file->web_path) || ! empty($file->thumbnail_path));
        });
        if ($withOptimized) {
            return $this->resolveOptimizedFileUrl($withOptimized);
        }

        $displayableExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $displayable = $shoot->files->first(function ($file) use ($displayableExtensions, $isExcluded) {
            if ($isExcluded($file)) {
                return false;
            }
            $filename = $file->filename ?? $file->path ?? '';
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            return in_array($ext, $displayableExtensions, true);
        });
        if ($displayable) {
            return $this->resolveOptimizedFileUrl($displayable);
        }

        $first = $shoot->files->first(function ($file) use ($isExcluded) {
            return ! $isExcluded($file);
        });

        return $first ? $this->resolveFileUrl($first, $allowDropboxCalls) : null;
    }

    protected function resolveFileUrl(?ShootFile $file, bool $allowDropboxCalls = true): ?string
    {
        return $this->fileAccessService->resolveFileUrl($file, $allowDropboxCalls);
    }

    protected function resolveOptimizedFileUrl(ShootFile $file): ?string
    {
        return $this->fileAccessService->resolveOptimizedFileUrl($file);
    }

    protected function getPrimaryActionForRole(Shoot $shoot, string $role): array
    {
        return match ($role) {
            'client' => $shoot->remaining_balance > 0
                ? ['label' => 'Pay Now', 'action' => 'pay']
                : ['label' => 'View Media', 'action' => 'view_media'],
            'photographer' => in_array($shoot->workflow_status, [
                Shoot::WORKFLOW_BOOKED,
                Shoot::WORKFLOW_RAW_UPLOAD_PENDING,
                Shoot::WORKFLOW_RAW_ISSUE,
            ], true)
                ? ['label' => 'Upload RAW', 'action' => 'upload_raw']
                : ['label' => 'Open Workflow', 'action' => 'open_workflow'],
            'editor' => ['label' => 'Upload Finals', 'action' => 'upload_final'],
            default => ['label' => 'Open Workflow', 'action' => 'open_workflow'],
        };
    }

    protected function calculatePaymentStatus(float $totalPaid, float $totalQuote): string
    {
        if ($totalQuote <= 0.01) {
            return 'paid';
        }

        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        if ($totalPaid >= $totalQuote) {
            return 'paid';
        }

        return 'partial';
    }
}
