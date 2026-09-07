<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\IguideOfflineViewerService;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShootPublicAssetsService
{
    public function __construct(
        protected ShootPaymentStatusSupport $paymentStatusSupport,
        protected ShootClientReleaseAccessService $shootClientReleaseAccessService,
        protected MediaStorage $mediaStorage,
        protected DeliveryMediaOrderService $deliveryMediaOrderService,
        protected ?IguideOfflineViewerService $iguideOfflineViewerService = null
    ) {
    }

    public function resolvePublicShoot(Request $request, $shootId = null): ?Shoot
    {
        if ($shootId) {
            return Shoot::with(['files', 'client'])->find($shootId);
        }

        $address = trim((string) $request->query('address', ''));
        $city = trim((string) $request->query('city', ''));
        $state = trim((string) $request->query('state', ''));
        $zip = trim((string) $request->query('zip', ''));

        if ($address === '' || $city === '' || $state === '') {
            return null;
        }

        $query = Shoot::with(['files', 'client'])
            ->whereRaw('LOWER(address) = ?', [strtolower($address)])
            ->whereRaw('LOWER(city) = ?', [strtolower($city)])
            ->whereRaw('LOWER(state) = ?', [strtolower($state)]);

        if ($zip !== '') {
            $query->where('zip', $zip);
        }

        return $query->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->first();
    }

    public function buildTypedPublicAssets(Shoot $shoot, string $type, bool $reconcilePayments = true): array
    {
        $assets = $this->buildPublicAssets($shoot, $reconcilePayments);
        $tourLinks = $this->normalizeTourLinks($shoot->tour_links ?? []);
        $propertyDetails = $this->buildPublicTourPropertyDetails($shoot, $tourLinks);
        $videoUrl = $this->resolveTypedVideoUrl($tourLinks, $type);
        $videoThumbnailUrl = $this->resolveVideoThumbnailUrl($videoUrl);

        // A manually published branded URL takes precedence over the provider
        // mirror on the shoot. A delivered, audience-attested uploaded package
        // is the most explicit source and becomes the branded or MLS viewer
        // without exposing the private ZIP itself.
        $explicitBrandedIguideUrl = $this->normalizePublicTourUrl($tourLinks['iguide_branded'] ?? null);
        $providerBrandedIguideUrl = $this->normalizePublicTourUrl($shoot->iguide_tour_url);
        $legacyBrandedIguideUrl = $this->firstFilled(
            $this->normalizePublicTourUrl($tourLinks['iGuide'] ?? null),
            $this->normalizePublicTourUrl($tourLinks['iguide'] ?? null)
        );
        $brandedIguideUrl = $this->firstFilled(
            $explicitBrandedIguideUrl,
            $providerBrandedIguideUrl,
            $legacyBrandedIguideUrl
        );
        $brandedIguideSource = match (true) {
            $explicitBrandedIguideUrl !== null && $explicitBrandedIguideUrl === $providerBrandedIguideUrl => 'provider_fetched',
            $explicitBrandedIguideUrl !== null => 'tour_links',
            $providerBrandedIguideUrl !== null => 'provider_fetched',
            $legacyBrandedIguideUrl !== null => 'legacy_tour_links',
            default => null,
        };
        $iguideMlsUrl = $this->normalizePublicTourUrl($tourLinks['iguide_mls'] ?? null);
        if ($iguideMlsUrl !== null && in_array($iguideMlsUrl, array_filter([
            $this->normalizePublicTourUrl($shoot->iguide_tour_url),
            $this->normalizePublicTourUrl($tourLinks['iguide_branded'] ?? null),
            $this->normalizePublicTourUrl($tourLinks['iGuide'] ?? null),
            $this->normalizePublicTourUrl($tourLinks['iguide'] ?? null),
        ]), true)) {
            // Historical iGUIDE syncs copied the branded URL into iguide_mls.
            // Treat an identical value as unverified and fail closed.
            $iguideMlsUrl = null;
        }
        $iguideMlsSource = $tourLinks['iguide_mls_source'] ?? null;
        if ($iguideMlsUrl !== null
            && is_string($iguideMlsSource)
            && ! in_array($iguideMlsSource, ['unbranded_url', 'manual', 'manual_url'], true)) {
            // A provenance marker exists but does not attest an unbranded source.
            $iguideMlsUrl = null;
        }
        $resolvedMlsSource = $iguideMlsUrl === null
            ? null
            : ($iguideMlsSource === 'unbranded_url' ? 'provider_unbranded' : 'tour_links_unbranded');
        if ($iguideMlsUrl === null) {
            unset($tourLinks['iguide_mls'], $tourLinks['iguide_mls_source']);
        } else {
            $tourLinks['iguide_mls'] = $iguideMlsUrl;
        }

        $matterportBrandedUrl = $this->firstFilled(
            $tourLinks['matterport_branded'] ?? null,
            $tourLinks['matterport'] ?? null
        );
        $matterportMlsUrl = $this->firstFilled($tourLinks['matterport_mls'] ?? null);
        if ($matterportMlsUrl !== null && in_array($matterportMlsUrl, array_filter([
            $tourLinks['matterport_branded'] ?? null,
            $tourLinks['matterport'] ?? null,
        ]), true)) {
            $matterportMlsUrl = null;
        }

        $isBranded = $type === 'branded';
        $offlineViewer = ($this->iguideOfflineViewerService ?? app(IguideOfflineViewerService::class))
            ->issuePublicViewerLinkIfEligible($shoot, $isBranded ? 'branded' : 'mls');
        if ($offlineViewer !== null) {
            $iguideUrl = $offlineViewer['url'];
            $iguideSource = 'published_offline_package';
            $iguideExpiresAt = $offlineViewer['expires_at'];
        } else {
            $iguideUrl = $isBranded ? ($brandedIguideUrl ?? $iguideMlsUrl) : $iguideMlsUrl;
            $iguideSource = $isBranded
                ? ($brandedIguideUrl !== null ? $brandedIguideSource : $resolvedMlsSource)
                : $resolvedMlsSource;
            $iguideExpiresAt = null;
        }
        $iguideInlineUrl = $offlineViewer !== null
            ? $iguideUrl
            : $this->resolveIguideInlineUrl($shoot, $iguideUrl, $iguideSource, $isBranded);

        // Canonicalize the response slots as well as the top-level aliases.
        // The dedicated /tour/3d redirect reads tour_links first; leaving a
        // stale provider/manual value there would make it open a different
        // viewer from the iGUIDE section on the property tour page.
        unset($tourLinks['iguide_branded'], $tourLinks['iGuide'], $tourLinks['iguide']);
        if ($isBranded && $iguideUrl !== null) {
            $tourLinks['iguide_branded'] = $iguideUrl;
        }

        // Do not return branded 3D destinations inside MLS/generic payloads.
        // zillow_3d has no unbranded equivalent and neither the MLS tour pages
        // nor the MLS 3D wrapper will open it, so leaving it here would make a
        // preview advertise a walkthrough the page cannot show - and disclose to
        // an MLS audience that a branded Zillow tour exists.
        if (! $isBranded) {
            unset(
                $tourLinks['iguide_branded'],
                $tourLinks['iGuide'],
                $tourLinks['iguide'],
                $tourLinks['matterport_branded'],
                $tourLinks['matterport'],
                $tourLinks['zillow_3d'],
                $tourLinks['cubicasa_branded'],
                $tourLinks['cubicasa'],
                $tourLinks['branded'],
                $tourLinks['video_branded'],
                $tourLinks['video_link'],
                $tourLinks['realtor_client_id'],
                $tourLinks['realtorClientId'],
                $tourLinks['realtor_info']
            );
            $tourLinks['embeds'] = $this->sanitizeUnbrandedEmbeds($tourLinks['embeds'] ?? null);
            if ($tourLinks['embeds'] === []) {
                unset($tourLinks['embeds'], $tourLinks['featured_embed'], $tourLinks['featured_embed_id']);
            } else {
                $validEmbedIds = array_column($tourLinks['embeds'], 'id');
                $featuredEmbedId = $tourLinks['featured_embed_id'] ?? $tourLinks['featured_embed'] ?? null;
                if (! is_string($featuredEmbedId) || ! in_array($featuredEmbedId, $validEmbedIds, true)) {
                    unset($tourLinks['featured_embed'], $tourLinks['featured_embed_id']);
                }
            }
            if ($iguideUrl === null) {
                unset($tourLinks['iguide_mls'], $tourLinks['iguide_mls_source']);
            } else {
                $tourLinks['iguide_mls'] = $iguideUrl;
                $tourLinks['iguide_mls_source'] = $offlineViewer !== null
                    ? 'uploaded_offline_package'
                    : ($iguideMlsSource ?: 'manual');
            }
        }

        $assets['type'] = $type;
        $assets['property_details'] = $propertyDetails;
        $assets['iguide_tour_url'] = $iguideUrl;
        $assets['iguide_url'] = $iguideUrl;
        $assets['iguide_viewer'] = [
            'source' => $iguideSource,
            'inline_url' => $iguideInlineUrl,
            'open_url' => $iguideUrl,
            'expires_at' => $iguideExpiresAt,
        ];
        $assets['iguide_floorplans'] = $shoot->iguide_floorplans;
        // Prefer localized floorplan files (with generated preview images) so the tour
        // shows real previews. Fall back to the iGUIDE JSON (external URLs) when no local
        // floorplan files exist; the frontend renders a clean fallback for those.
        $localFloorplans = $this->buildFloorplanAssets($shoot);
        $assets['floorplans'] = !empty($localFloorplans) ? $localFloorplans : $shoot->iguide_floorplans;
        $assets['matterport_url'] = $isBranded ? $matterportBrandedUrl : $matterportMlsUrl;
        $assets['video_link'] = $videoUrl;
        $assets['video_thumbnail_url'] = $videoThumbnailUrl;
        $assets['video_poster_url'] = $videoThumbnailUrl;
        $assets['embeds'] = $tourLinks['embeds'] ?? [];
        $assets['tour_links'] = $tourLinks;
        $assets['tour_style'] = $tourLinks['tour_style'] ?? 'default';
        $assets['show_garage'] = !empty($tourLinks['show_garage']);

        if (! $isBranded) {
            foreach (['client_name', 'client_company', 'client_email', 'client_phone', 'client_avatar'] as $identityKey) {
                unset($assets['shoot'][$identityKey]);
            }
            unset($assets['branding']);
        }

        if ($type === 'branded') {
            $effectiveClient = $this->resolveEffectiveBrandedClient($shoot, $tourLinks);
            if ($effectiveClient) {
                $assets['shoot']['client_name'] = $effectiveClient->name;
                $assets['shoot']['client_company'] = $effectiveClient->company_name;
                $assets['shoot']['client_email'] = $effectiveClient->email;
                $assets['shoot']['client_phone'] = $effectiveClient->phone ?? $effectiveClient->phonenumber;
                $assets['shoot']['client_avatar'] = $effectiveClient->avatar;

                $branding = DB::table('user_branding')->where('user_id', $effectiveClient->id)->first();
                if ($branding) {
                    $assets['branding'] = [
                        'logo' => $branding->logo,
                        'banner' => $branding->banner ?? null,
                        'primary_color' => $branding->primary_color,
                        'secondary_color' => $branding->secondary_color,
                        'font_family' => $branding->font_family,
                        'about' => $branding->about ?? null,
                        'hero_headline' => $branding->hero_headline ?? null,
                        'hero_subtitle' => $branding->hero_subtitle ?? null,
                        'hero_image' => $branding->hero_image ?? null,
                        'facebook_url' => $branding->facebook_url,
                        'linkedin_url' => $branding->linkedin_url,
                        'instagram_url' => $branding->instagram_url,
                        'show_map' => (bool) ($branding->show_map ?? false),
                    ];
                }
            }
        }

        return $assets;
    }

    public function buildPublicTourPropertyDetails(Shoot $shoot, ?array $tourLinks = null): array
    {
        $tourLinks = $tourLinks ?? $this->normalizeTourLinks($shoot->tour_links ?? []);
        $propertyDetails = $shoot->property_details ?? [];
        if (is_string($propertyDetails)) {
            $propertyDetails = json_decode($propertyDetails, true) ?? [];
        }
        if (!is_array($propertyDetails)) {
            $propertyDetails = [];
        }

        $bedrooms = $this->firstFilled(
            $propertyDetails['bedrooms'] ?? null,
            $propertyDetails['beds'] ?? null,
            $propertyDetails['bed'] ?? null
        );
        $bathrooms = $this->firstFilled(
            $propertyDetails['bathrooms'] ?? null,
            $propertyDetails['baths'] ?? null,
            $propertyDetails['bath'] ?? null
        );
        $sqft = $this->firstFilled(
            $propertyDetails['sqft'] ?? null,
            $propertyDetails['squareFeet'] ?? null,
            $propertyDetails['square_feet'] ?? null,
            $propertyDetails['livingArea'] ?? null,
            $propertyDetails['living_area'] ?? null
        );
        $mlsId = $this->firstFilled(
            $tourLinks['property_mls'] ?? null,
            $shoot->mls_id,
            $propertyDetails['mls_id'] ?? null,
            $propertyDetails['mlsId'] ?? null,
            $propertyDetails['mlsNumber'] ?? null
        );
        $price = $this->firstFilled(
            $tourLinks['property_price'] ?? null,
            $propertyDetails['price'] ?? null,
            $propertyDetails['listPrice'] ?? null,
            $propertyDetails['listingPrice'] ?? null
        );
        $lotSize = $this->firstFilled(
            $tourLinks['property_lot_size'] ?? null,
            $propertyDetails['lot_size'] ?? null,
            $propertyDetails['lotSize'] ?? null,
            $propertyDetails['lotSizeSqft'] ?? null
        );
        $description = $this->firstFilled(
            $tourLinks['property_description'] ?? null,
            $propertyDetails['description'] ?? null,
            $propertyDetails['property_description'] ?? null
        );
        $listingType = $this->firstFilled(
            $shoot->listing_type,
            $propertyDetails['listing_type'] ?? null,
            $propertyDetails['listingType'] ?? null
        );
        $propertyStatus = $this->firstFilled(
            $shoot->property_status,
            $propertyDetails['property_status'] ?? null,
            $propertyDetails['propertyStatus'] ?? null,
            $propertyDetails['status'] ?? null
        );

        return array_merge($propertyDetails, [
            'beds' => $bedrooms,
            'bedrooms' => $bedrooms,
            'baths' => $bathrooms,
            'bathrooms' => $bathrooms,
            'sqft' => $sqft,
            'squareFeet' => $sqft,
            'square_feet' => $sqft,
            'mls_id' => $mlsId,
            'mlsId' => $mlsId,
            'price' => $price,
            'lot_size' => $lotSize,
            'lotSize' => $lotSize,
            'description' => $description,
            'listing_type' => $listingType,
            'listingType' => $listingType,
            'property_status' => $propertyStatus,
            'propertyStatus' => $propertyStatus,
        ]);
    }

    public function buildClientProfilePayload(User $viewer, User $client): ?array
    {
        if (!$this->canViewClientProfile($viewer, $client)) {
            return null;
        }

        return $this->buildPublicClientProfilePayload($client);
    }

    public function buildPublicClientProfilePayload(User $client): array
    {
        $portfolioClientIds = $this->resolvePortfolioClientIds($client);
        $shootsQuery = Shoot::with(['files'])->whereIn('client_id', $portfolioClientIds);

        $visibleStatuses = [
            Shoot::STATUS_REQUESTED,
            Shoot::STATUS_SCHEDULED,
            Shoot::STATUS_UPLOADED,
            Shoot::STATUS_EDITING,
            Shoot::STATUS_READY,
            Shoot::STATUS_DELIVERED,
            'booked',
            'completed',
            'ready_for_client',
            'admin_verified',
            'workflow_completed',
            'client_delivered',
            'finalized',
            'finalised',
        ];

        $shoots = $shootsQuery
            ->where(function ($query) use ($visibleStatuses) {
                $query->whereIn('status', $visibleStatuses)
                    ->orWhereIn('workflow_status', $visibleStatuses)
                    ->orWhereNotNull('admin_verified_at');
            })
            ->orderByDesc('scheduled_date')
            ->get();

        $shootItems = $shoots->map(function (Shoot $shoot) use ($shoots) {
            $files = $shoot->files ?: collect();
            $preview = $shoot->hero_image ? $this->resolveLocalPublicUrl($shoot->hero_image) : null;

            if (!$preview) {
                $verifiedImage = $files->where('workflow_stage', ShootFile::STAGE_VERIFIED)
                    ->first(fn (ShootFile $file) => str_starts_with(strtolower((string) $file->file_type), 'image/'));
                if ($verifiedImage) {
                    $preview = $this->resolveClientProfileFileUrl($verifiedImage, $shoots, 'thumbnail');
                }
            }

            if (!$preview) {
                $imageFile = $files->first(fn (ShootFile $file) => str_starts_with(strtolower((string) $file->file_type), 'image/'));
                if ($imageFile) {
                    $preview = $this->resolveClientProfileFileUrl($imageFile, $shoots, 'thumbnail');
                }
            }

            $gallery = $files->filter(fn (ShootFile $file) => str_starts_with(strtolower((string) $file->file_type), 'image/'))
                ->map(fn (ShootFile $file) => $this->resolveClientProfileFileUrl($file, $shoots, 'web'))
                ->filter()
                ->values()
                ->toArray();

            $tourLinks = $this->normalizeTourLinks($shoot->tour_links ?? []);
            $propDetails = $this->buildPublicTourPropertyDetails($shoot, $tourLinks);
            $deliveredStatuses = [
                Shoot::STATUS_DELIVERED,
                'ready_for_client',
                'admin_verified',
                'workflow_completed',
                'client_delivered',
                'finalized',
                'finalised',
            ];
            $isDelivered = in_array(strtolower((string) $shoot->status), $deliveredStatuses, true)
                || in_array(strtolower((string) $shoot->workflow_status), $deliveredStatuses, true)
                || $shoot->admin_verified_at !== null;

            return [
                'id' => $shoot->id,
                'address' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'scheduled_date' => optional($shoot->scheduled_date)->toDateString(),
                'status' => $shoot->status,
                'workflow_status' => $shoot->workflow_status,
                'is_delivered' => $isDelivered,
                'files_count' => $files->count(),
                'preview_image' => $preview,
                'gallery' => $gallery,
                'iguide_tour_url' => $shoot->iguide_tour_url
                    ?? $tourLinks['iguide_branded']
                    ?? $tourLinks['iguide_mls']
                    ?? $tourLinks['iGuide']
                    ?? null,
                'tour_links' => $tourLinks,
                'branded_tour_url' => $this->buildPublicTourUrl($shoot->id, 'branded'),
                'listing_type' => $shoot->listing_type,
                'property_status' => $propDetails['property_status'] ?? $propDetails['status'] ?? $shoot->property_status ?? 'available',
                'bedrooms' => $propDetails['bedrooms'] ?? $propDetails['beds'] ?? null,
                'bathrooms' => $propDetails['bathrooms'] ?? $propDetails['baths'] ?? null,
                'sqft' => $propDetails['sqft'] ?? $propDetails['square_feet'] ?? null,
                'price' => $propDetails['price'] ?? null,
                'lot_size' => $propDetails['lot_size'] ?? $propDetails['lotSize'] ?? null,
                'mls_id' => $propDetails['mls_id'] ?? $shoot->mls_id,
            ];
        });

        $branding = DB::table('user_branding')->where('user_id', $client->id)->first();
        $clientMeta = $client->metadata ?? [];
        if (!is_array($clientMeta)) {
            $clientMeta = [];
        }

        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'company_name' => $client->company_name,
                'phonenumber' => $client->phonenumber,
                'phone' => $client->phone ?? $client->phonenumber,
                'avatar' => $client->avatar,
                'about' => $branding->about ?? null,
                'address' => $client->address,
                'facebook_url' => $branding->facebook_url ?? null,
                'twitter_url' => $client->twitter_url,
                'linkedin_url' => $branding->linkedin_url ?? null,
                'pinterest_url' => $client->pinterest_url,
                'banner_image' => $branding->banner ?? null,
                'logo' => $branding->logo ?? null,
                'hero_headline' => $branding->hero_headline ?? null,
                'hero_subtitle' => $branding->hero_subtitle ?? null,
                'hero_image' => $branding->hero_image ?? null,
                'instagram_url' => $branding->instagram_url ?? null,
                'show_map' => (bool) ($branding->show_map ?? false),
                'rep' => $this->resolveRepInfo($clientMeta),
            ],
            'shoots' => $shootItems,
        ];
    }

    public function resolvePropertyDescriptionImageUrls(Shoot $shoot): array
    {
        $editedFiles = $shoot->files()
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->where(function ($q) {
                $q->where('media_type', '!=', 'floorplan')->orWhereNull('media_type');
            })
            ->where(function ($query) {
                $query->where(function ($mimeQuery) {
                    $mimeQuery->where('file_type', 'like', 'image/%')
                        ->orWhere('mime_type', 'like', 'image/%');
                })->orWhere(function ($fileQuery) {
                    $fileQuery->where('filename', 'like', '%.jpg')
                        ->orWhere('filename', 'like', '%.jpeg')
                        ->orWhere('filename', 'like', '%.png')
                        ->orWhere('stored_filename', 'like', '%.jpg')
                        ->orWhere('stored_filename', 'like', '%.jpeg')
                        ->orWhere('stored_filename', 'like', '%.png');
                });
            })
            ->inDeliveryOrder()
            ->get();

        if ($editedFiles->isEmpty()) {
            return [];
        }

        $total = $editedFiles->count();
        $indices = array_unique(array_map(
            fn ($pct) => min((int) round($pct * ($total - 1)), $total - 1),
            [0, 0.25, 0.5, 0.75, 1.0]
        ));
        $selectedFiles = collect($indices)->map(fn ($index) => $editedFiles->values()[$index])->unique('id')->take(5);

        $imageUrls = [];
        foreach ($selectedFiles as $file) {
            $url = null;
            foreach ([
                $file->web_path ?? null,
                $file->thumbnail_path ?? null,
                $file->storage_path ?? null,
                $file->path ?? null,
                $file->url ?? null,
            ] as $candidate) {
                if (!$candidate) {
                    continue;
                }

                if (preg_match('/^https?:\/\//i', $candidate)) {
                    $url = $candidate;
                    break;
                }

                $resolved = $this->resolveLocalPublicUrl($candidate);
                if ($resolved) {
                    $url = $resolved;
                    break;
                }
            }



            if ($url) {
                $imageUrls[] = $url;
            }
        }

        return $imageUrls;
    }

    protected function buildPublicTourUrl(int|string $shootId, string $type): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', ''), '/');
        if ($frontendUrl === '') {
            $frontendUrl = rtrim((string) config('app.url', ''), '/');
        }

        return $frontendUrl . '/tour/' . $type . '?shootId=' . urlencode((string) $shootId);
    }

    /**
     * @param  bool  $reconcilePayments  Pass false for read-only consumers that
     *   never look at payment state. Reconciliation calls the Stripe API, which
     *   measured at ~5.5s on a shoot with an open balance - far too slow for the
     *   link-preview endpoint the edge hits on every page view.
     */
    protected function buildPublicAssets(Shoot $shoot, bool $reconcilePayments = true): array
    {
        $shoot = $reconcilePayments
            ? $this->paymentStatusSupport->reconcileStripePaymentState($shoot, ['files', 'client', 'payments'])
            : $shoot->loadMissing(['files', 'client', 'payments']);
        $files = $shoot->files;
        $chosen = $files->where('workflow_stage', ShootFile::STAGE_VERIFIED);
        if ($chosen->isEmpty()) {
            $chosen = $files->where('workflow_stage', ShootFile::STAGE_COMPLETED);
        }
        if ($chosen->isEmpty()) {
            $chosen = $files->where('workflow_stage', ShootFile::STAGE_TODO);
        }

        // The gallery a client browses and the ZIP they download must agree, so
        // this replays the same delivery order (and the same finalize snapshot)
        // the archive builder uses. The previous hand-rolled
        // `sort_order asc, id asc` disagreed on unplaced files: a sort_order of 0
        // sorted ahead of curated position 1, pushing never-arranged media to the
        // top of the gallery while the archive kept it at the end.
        $chosen = $this->deliveryMediaOrderService->applyTo($shoot, $chosen);

        $photos = [];
        $heroPhotos = [];
        $videos = [];
        foreach ($chosen as $file) {
            // Floorplans have their own "Floor Plans" section and must never appear in
            // the property photo gallery or hero. (They now carry a generated preview
            // web_path, so they must be excluded explicitly here.)
            if (strtolower((string) $file->media_type) === 'floorplan') {
                continue;
            }

            $url = $this->resolvePublicAssetFileUrl($file);
            if (!$url) {
                continue;
            }

            $type = strtolower((string) $file->file_type);
            $isImage = str_starts_with($type, 'image/');
            $isVideo = str_starts_with($type, 'video/');

            if (!$isImage && !$isVideo) {
                $extension = strtolower(pathinfo($file->filename ?? $file->stored_filename ?? '', PATHINFO_EXTENSION));
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif'], true)) {
                    $isImage = true;
                } elseif (in_array($extension, ['mp4', 'mov', 'avi', 'webm', 'ogg'], true)) {
                    $isVideo = true;
                }
            }

            if ($isImage) {
                $photos[] = $url;
                if (!empty($file->is_cover)) {
                    $heroPhotos[] = $url;
                }
            } elseif ($isVideo) {
                $videos[] = $url;
            }
        }

        return [
            'shoot' => [
                'id' => $shoot->id,
                'client_name' => optional($shoot->client)->name,
                'client_company' => optional($shoot->client)->company_name,
                'address' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'scheduled_date' => optional($shoot->scheduled_date)->toDateString(),
            ],
            'photos' => array_values(array_unique($photos)),
            'hero_photos' => array_values(array_unique($heroPhotos)),
            'videos' => array_values(array_unique($videos)),
            'tours' => [
                'matterport' => null,
                'iGuide' => null,
                'cubicasa' => null,
            ],
        ];
    }

    protected function resolveEffectiveBrandedClient(Shoot $shoot, array $tourLinks): ?User
    {
        $assignedRealtorId = $tourLinks['realtor_client_id'] ?? $tourLinks['realtorClientId'] ?? null;
        if ($assignedRealtorId !== null && $assignedRealtorId !== '') {
            $assignedClient = User::query()
                ->where('role', 'client')
                ->find($assignedRealtorId);

            if ($assignedClient) {
                return $assignedClient;
            }
        }

        return $shoot->client;
    }

    protected function canViewClientProfile(User $viewer, User $client): bool
    {
        if (in_array($viewer->role, ['admin', 'superadmin'], true)) {
            return true;
        }
        if ($viewer->role === 'client' && (string) $viewer->id === (string) $client->id) {
            return true;
        }
        if ($viewer->role !== 'salesRep') {
            return false;
        }

        $metadata = $client->metadata ?? [];
        $repId = $metadata['accountRepId']
            ?? $metadata['account_rep_id']
            ?? $metadata['repId']
            ?? $metadata['rep_id']
            ?? null;

        if ($repId && (string) $repId === (string) $viewer->id) {
            return true;
        }
        if ($client->created_by_id && (string) $client->created_by_id === (string) $viewer->id) {
            return true;
        }

        return Shoot::where('client_id', $client->id)
            ->where('rep_id', $viewer->id)
            ->exists();
    }

    protected function resolveRepInfo(array $clientMeta): ?array
    {
        $repId = $clientMeta['accountRepId']
            ?? $clientMeta['account_rep_id']
            ?? $clientMeta['repId']
            ?? $clientMeta['rep_id']
            ?? null;

        if (!$repId) {
            return null;
        }

        $rep = User::find($repId);
        if (!$rep) {
            return null;
        }

        return [
            'id' => (string) $rep->id,
            'name' => $rep->name,
            'email' => $rep->email,
            'phone' => $rep->phone ?? $rep->phonenumber,
            'avatar' => $rep->avatar,
        ];
    }

    protected function resolvePortfolioClientIds(User $owner): array
    {
        $linkedClientIds = DB::table('user_branding_clients')
            ->where('user_id', $owner->id)
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        return array_values(
            array_unique(
                array_merge([(int) $owner->id], $linkedClientIds)
            )
        );
    }

    protected function resolveClientProfileFileUrl(ShootFile $file, Collection $shoots, string $size = 'web'): ?string
    {
        $shoot = $shoots->firstWhere('id', $file->shoot_id);
        if (!$shoot) {
            return null;
        }

        $paymentStatus = $shoot->payment_status;
        if (!$paymentStatus || $paymentStatus === 'pending') {
            $paymentStatus = $this->paymentStatusSupport->calculatePaymentStatus(
                (float) ($shoot->total_paid ?? 0),
                (float) ($shoot->total_quote ?? 0)
            );
        }

        $needsWatermark = !$shoot->bypass_paywall && $paymentStatus !== 'paid';
        if ($needsWatermark) {
            $watermarkedPath = match ($size) {
                'thumbnail' => $file->watermarked_thumbnail_path ?? $file->watermarked_placeholder_path,
                'placeholder' => $file->watermarked_placeholder_path ?? $file->watermarked_thumbnail_path,
                default => $file->watermarked_web_path
                    ?? $file->watermarked_thumbnail_path
                    ?? $file->watermarked_placeholder_path,
            };

            if ($watermarkedPath) {
                return $this->resolveWatermarkedPath($watermarkedPath);
            }

            if ($file->shouldBeWatermarked()) {
                try {
                    $watermarkJob = new \App\Jobs\GenerateWatermarkedImageJob($file->fresh());
                    $watermarkJob->handle(app(\App\Services\ShootMediaStorageService::class));
                    $file->refresh();

                    return $this->resolveClientProfileFileUrl($file, $shoots, $size);
                } catch (\Exception $e) {
                    \App\Jobs\GenerateWatermarkedImageJob::dispatch($file->fresh());
                    Log::warning('Failed to generate watermark synchronously for client profile', [
                        'file_id' => $file->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return null;
        }

        return $this->resolveLocalPublicUrl($file->web_path ?? $file->thumbnail_path);
    }

    protected function resolvePublicAssetFileUrl(ShootFile $file): ?string
    {
        foreach ([$file->web_path ?? null, $file->thumbnail_path ?? null] as $candidate) {
            $resolved = $this->resolveLocalPublicUrl($candidate);
            if ($resolved) {
                return $resolved;
            }
        }

        if (!empty($file->url) && preg_match('/^https?:\/\//i', $file->url)) {
            return $file->url;
        }

        foreach ([
            $file->path ?? null,
            'shoots/' . $file->shoot_id . '/final/' . ($file->stored_filename ?? $file->filename ?? ''),
            'shoots/' . $file->shoot_id . '/completed/' . ($file->stored_filename ?? $file->filename ?? ''),
        ] as $candidate) {
            $resolved = $this->resolveLocalPublicUrl($candidate);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    protected function normalizeTourLinks($tourLinks): array
    {
        if (is_string($tourLinks)) {
            return json_decode($tourLinks, true) ?? [];
        }

        return is_array($tourLinks) ? $tourLinks : [];
    }

    protected function normalizePublicTourUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);
        if ($url === ''
            || strlen($url) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /** @return list<array{id:string,title:string,mls:string}> */
    protected function sanitizeUnbrandedEmbeds(mixed $embeds): array
    {
        if (! is_array($embeds)) {
            return [];
        }

        return collect($embeds)
            ->filter(static fn (mixed $embed): bool => is_array($embed))
            ->map(function (array $embed, int|string $index): ?array {
                $mls = $embed['mls'] ?? $embed['mls_embed'] ?? null;
                if (! is_string($mls) || trim($mls) === '') {
                    return null;
                }

                $id = $embed['id'] ?? 'embed-' . $index;
                $title = $embed['title'] ?? 'Embed ' . ((int) $index + 1);

                return [
                    'id' => is_string($id) && trim($id) !== '' ? trim($id) : 'embed-' . $index,
                    'title' => is_string($title) && trim($title) !== '' ? trim($title) : 'Embed ' . ((int) $index + 1),
                    'mls' => trim($mls),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function resolveIguideInlineUrl(
        Shoot $shoot,
        ?string $openUrl,
        ?string $source,
        bool $isBranded
    ): ?string {
        if ($openUrl === null) {
            return null;
        }

        if ($isBranded && $source === 'provider_fetched') {
            $storedEmbed = $this->normalizePublicTourUrl(data_get($shoot->iguide_data, 'embedded_url'));
            if ($storedEmbed !== null && $this->sameIguideView($openUrl, $storedEmbed)) {
                return $this->deriveIguideEmbedUrl($storedEmbed, false) ?? $storedEmbed;
            }
        }

        return $this->deriveIguideEmbedUrl($openUrl, ! $isBranded) ?? $openUrl;
    }

    protected function deriveIguideEmbedUrl(string $url, bool $unbranded): ?string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (! preg_match('/^(?:unbranded\.)?(youriguide|iguidephotos|iguideradix)\.com$/D', $host, $match)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/')), 'strlen'));
        $viewPath = ($segments[0] ?? null) === 'embed' ? ($segments[1] ?? null) : ($segments[0] ?? null);
        if (! is_string($viewPath) || $viewPath === '' || preg_match('/^[A-Za-z0-9._~-]+$/D', $viewPath) !== 1) {
            return null;
        }

        $baseDomain = $match[1] . '.com';
        $embedHost = $unbranded ? 'unbranded.' . $baseDomain : $host;
        $query = [];
        if (is_string($parts['query'] ?? null)) {
            parse_str($parts['query'], $query);
        }
        $query['autostart'] = '1';
        $query['noinitanimation'] = '1';
        if ($unbranded) {
            $query['unbranded'] = '1';
            $query['nomenu'] = '1';
            $query['nodetails'] = '1';
        }

        return 'https://' . $embedHost . '/embed/' . rawurlencode($viewPath) . '/'
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    protected function sameIguideView(string $first, string $second): bool
    {
        $viewPath = static function (string $url): ?string {
            $parts = parse_url($url);
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            if (! preg_match('/^(?:unbranded\.)?(youriguide|iguidephotos|iguideradix)\.com$/D', $host, $hostMatch)) {
                return null;
            }
            $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/')), 'strlen'));
            $path = ($segments[0] ?? null) === 'embed' ? ($segments[1] ?? null) : ($segments[0] ?? null);

            return is_string($path) && $path !== '' ? $hostMatch[1] . ':' . $path : null;
        };

        $firstView = $viewPath($first);

        return $firstView !== null && hash_equals($firstView, (string) $viewPath($second));
    }

    protected function resolveTypedVideoUrl(array $tourLinks, string $type): ?string
    {
        $videoKey = match ($type) {
            'branded' => 'video_branded',
            'mls' => 'video_mls',
            'generic-mls' => 'video_generic',
            default => 'video_link',
        };

        $value = $tourLinks[$videoKey] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    protected function resolveVideoThumbnailUrl(?string $videoUrl): ?string
    {
        if (!$videoUrl) {
            return null;
        }

        $youtubeId = $this->extractYoutubeVideoId($videoUrl);
        if ($youtubeId) {
            return sprintf('https://i.ytimg.com/vi/%s/hqdefault.jpg', $youtubeId);
        }

        $vimeoId = $this->extractVimeoVideoId($videoUrl);
        if (!$vimeoId) {
            return null;
        }

        return Cache::remember(
            "public_video_thumbnail:vimeo:{$vimeoId}",
            now()->addHours(12),
            function () use ($vimeoId) {
                try {
                    $response = Http::acceptJson()
                        ->timeout(5)
                        ->get('https://vimeo.com/api/oembed.json', [
                            'url' => "https://vimeo.com/{$vimeoId}",
                        ]);

                    if (!$response->ok()) {
                        return null;
                    }

                    $thumbnailUrl = trim((string) $response->json('thumbnail_url'));

                    return $thumbnailUrl !== '' ? $thumbnailUrl : null;
                } catch (\Throwable $exception) {
                    \App\Services\ApiErrorResponder::log($exception, 'warning');

                    return null;
                }
            }
        );
    }

    protected function firstFilled(...$values)
    {
        foreach ($values as $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
                continue;
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function extractYoutubeVideoId(string $videoUrl): ?string
    {
        if (!preg_match(
            '/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i',
            $videoUrl,
            $matches
        )) {
            return null;
        }

        return $matches[1] ?? null;
    }

    protected function extractVimeoVideoId(string $videoUrl): ?string
    {
        if (!preg_match('/(?:player\.)?vimeo\.com\/(?:video\/)?(\d+)/i', $videoUrl, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    protected function resolveWatermarkedPath(?string $path): ?string
    {
        $resolved = $this->resolveLocalPublicUrl($path);
        if ($resolved) {
            return $resolved;
        }



        return null;
    }

    protected function resolveLocalPublicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $clean = ltrim($path, '/');
        $relative = str_starts_with($clean, 'storage/') ? substr($clean, 8) : $clean;

        // Public/delivered/tour assets resolve to the R2 CDN once reads are flipped.
        if ($this->mediaStorage->readFromR2Enabled() || $this->mediaStorage->r2Only()) {
            if ($this->mediaStorage->existsOnR2($relative)) {
                return $this->mediaStorage->publicUrl($relative);
            }
            if ($this->mediaStorage->r2Only()) {
                return null;
            }
        }

        if (Storage::disk('public')->exists($relative)) {
            $url = Storage::disk('public')->url($relative);
            if (!preg_match('/^https?:\/\//i', $url)) {
                $url = rtrim(config('app.url'), '/') . '/' . ltrim($url, '/');
            }

            return $url;
        }

        return null;
    }

    /**
     * Build public floorplan entries from localized floorplan files, each with a real
     * preview image (generated JPG for PDFs, or the image itself) plus the original for
     * download. Returns [] when the shoot has no localized floorplan files.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildFloorplanAssets(Shoot $shoot): array
    {
        $files = \App\Models\ShootFile::query()
            ->where('shoot_id', $shoot->id)
            ->where('media_type', 'floorplan')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($files as $file) {
            $meta = is_array($file->metadata) ? $file->metadata : [];
            $hasPreviewImages = !empty($meta['preview_images']) && is_array($meta['preview_images']);
            if (!$file->web_path && !$file->thumbnail_path && !$hasPreviewImages) {
                try {
                    app(FloorplanPreviewService::class)->ensurePreview($file);
                    $file->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to ensure public floorplan preview', [
                        'shoot_file_id' => $file->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $meta = is_array($file->metadata) ? $file->metadata : [];
            $image = $this->resolveLocalPublicUrl($file->web_path ?: $file->thumbnail_path);
            $original = $this->resolveLocalPublicUrl($file->path ?: $file->storage_path);

            if (!$image && !$original) {
                continue;
            }

            $pages = [];
            if (!empty($meta['preview_images']) && is_array($meta['preview_images'])) {
                foreach ($meta['preview_images'] as $previewPath) {
                    $resolved = is_string($previewPath) ? $this->resolveLocalPublicUrl($previewPath) : null;
                    if ($resolved) {
                        $pages[] = $resolved;
                    }
                }
            }

            $isPdf = str_contains(strtolower((string) ($file->file_type ?? '')), 'pdf')
                || str_ends_with(strtolower((string) $file->filename), '.pdf');

            $out[] = [
                'label' => $meta['floor_name'] ?? $meta['label'] ?? $file->filename,
                'filename' => $file->filename,
                'url' => $original ?: $image,
                'original_url' => $original,
                'image' => $image,
                'preview_images' => $pages,
                'type' => $isPdf ? 'pdf' : 'image',
            ];
        }

        return $out;
    }
}
