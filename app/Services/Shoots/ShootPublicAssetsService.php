<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShootPublicAssetsService
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootPaymentStatusSupport $paymentStatusSupport,
        protected ShootClientReleaseAccessService $shootClientReleaseAccessService
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

    public function buildTypedPublicAssets(Shoot $shoot, string $type): array
    {
        if ($this->shootClientReleaseAccessService->isPublicReleaseLocked($shoot)) {
            return $this->shootClientReleaseAccessService->buildLockedPublicPayload($shoot, $type);
        }

        $assets = $this->buildPublicAssets($shoot);
        $tourLinks = $this->normalizeTourLinks($shoot->tour_links ?? []);

        $iguideUrl = match ($type) {
            'branded' => $shoot->iguide_tour_url
                ?? $tourLinks['iguide_branded']
                ?? $tourLinks['iGuide']
                ?? $tourLinks['iguide_mls']
                ?? null,
            default => $shoot->iguide_tour_url
                ?? $tourLinks['iguide_mls']
                ?? $tourLinks['iguide_branded']
                ?? $tourLinks['iGuide']
                ?? null,
        };

        $assets['type'] = $type;
        $assets['property_details'] = $shoot->property_details;
        $assets['iguide_tour_url'] = $iguideUrl;
        $assets['iguide_url'] = $iguideUrl;
        $assets['iguide_floorplans'] = $shoot->iguide_floorplans;
        $assets['floorplans'] = $shoot->iguide_floorplans;
        $assets['matterport_url'] = $type === 'branded'
            ? ($tourLinks['matterport_branded'] ?? $tourLinks['matterport'] ?? null)
            : ($tourLinks['matterport_mls'] ?? $tourLinks['matterport'] ?? null);
        $assets['embeds'] = $tourLinks['embeds'] ?? [];
        $assets['tour_links'] = $tourLinks;
        $assets['tour_style'] = $tourLinks['tour_style'] ?? 'default';
        $assets['show_garage'] = !empty($tourLinks['show_garage']);

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

        $shoots = $shootsQuery
            ->whereIn('status', [
                Shoot::STATUS_COMPLETED,
                Shoot::STATUS_DELIVERED,
                Shoot::STATUS_SCHEDULED,
                Shoot::STATUS_REQUESTED,
            ])
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
            $propDetails = $shoot->property_details ?? [];

            return [
                'id' => $shoot->id,
                'address' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'scheduled_date' => optional($shoot->scheduled_date)->toDateString(),
                'status' => $shoot->status,
                'files_count' => $files->count(),
                'preview_image' => $preview,
                'gallery' => $gallery,
                'iguide_tour_url' => $shoot->iguide_tour_url
                    ?? $tourLinks['iguide_branded']
                    ?? $tourLinks['iguide_mls']
                    ?? $tourLinks['iGuide']
                    ?? null,
                'tour_links' => $tourLinks,
                'listing_type' => $shoot->listing_type,
                'property_status' => $propDetails['property_status'] ?? $propDetails['status'] ?? 'available',
                'bedrooms' => $propDetails['bedrooms'] ?? $propDetails['beds'] ?? null,
                'bathrooms' => $propDetails['bathrooms'] ?? $propDetails['baths'] ?? null,
                'sqft' => $propDetails['sqft'] ?? $propDetails['square_feet'] ?? null,
                'price' => $propDetails['price'] ?? null,
                'mls_id' => $shoot->mls_id,
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
            ->orderBy('sort_order')
            ->orderBy('id')
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
            foreach ([$file->web_path ?? null, $file->storage_path ?? null, $file->path ?? null] as $candidate) {
                if (!$candidate) {
                    continue;
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

    protected function buildPublicAssets(Shoot $shoot): array
    {
        $shoot = $this->paymentStatusSupport->reconcileStripePaymentState($shoot, ['files', 'client', 'payments']);
        $files = $shoot->files;
        $chosen = $files->where('workflow_stage', ShootFile::STAGE_VERIFIED);
        if ($chosen->isEmpty()) {
            $chosen = $files->where('workflow_stage', ShootFile::STAGE_COMPLETED);
        }
        if ($chosen->isEmpty()) {
            $chosen = $files->where('workflow_stage', ShootFile::STAGE_TODO);
        }

        $photos = [];
        $videos = [];
        foreach ($chosen as $file) {
            $url = $this->resolvePublicAssetFileUrl($file);
            if (!$url) {
                continue;
            }

            $type = strtolower((string) $file->file_type);
            if (str_starts_with($type, 'image/')) {
                $photos[] = $url;
                continue;
            }
            if (str_starts_with($type, 'video/')) {
                $videos[] = $url;
                continue;
            }

            $extension = strtolower(pathinfo($file->filename ?? $file->stored_filename ?? '', PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif'], true)) {
                $photos[] = $url;
            } elseif (in_array($extension, ['mp4', 'mov', 'avi', 'webm', 'ogg'], true)) {
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
                    $watermarkJob->handle(app(DropboxWorkflowService::class));
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

    protected function resolveWatermarkedPath(?string $path): ?string
    {
        $resolved = $this->resolveLocalPublicUrl($path);
        if ($resolved) {
            return $resolved;
        }

        try {
            return $path ? $this->dropboxService->getTemporaryLink($path) : null;
        } catch (\Exception $e) {
            Log::warning('Failed to resolve watermarked path', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
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
        if (Storage::disk('public')->exists($relative)) {
            $url = Storage::disk('public')->url($relative);
            if (!preg_match('/^https?:\/\//i', $url)) {
                $url = rtrim(config('app.url'), '/') . '/' . ltrim($url, '/');
            }

            return $url;
        }

        return null;
    }
}
