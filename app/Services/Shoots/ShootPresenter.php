<?php

namespace App\Services\Shoots;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;

class ShootPresenter
{
    public function __construct(protected ShootFileAccessService $fileAccessService)
    {
    }

    public function transformOperationalShoot(Shoot $shoot, bool $isClientUser): array
    {
        $transformedShoot = $this->transformShoot($shoot);
        $shootArray = $transformedShoot->toArray();
        $servicesArray = $transformedShoot->getAttribute('services_list')
            ?? $transformedShoot->services->pluck('name')->filter()->values()->all();
        $shootArray['services_list'] = $servicesArray;

        if (!isset($shootArray['services']) || !is_array($shootArray['services'])) {
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
            && !($shootArray['bypass_paywall'] ?? false)
            && !in_array($shootArray['payment_status'] ?? '', ['paid', 'full'], true);

        if (isset($shootArray['files']) && is_array($shootArray['files'])) {
            $shootArray['files'] = collect($shootArray['files'])->map(function ($file) use ($needsWatermark) {
                $resolveUrl = function ($path) {
                    if (!$path) {
                        return null;
                    }
                    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                        return $path;
                    }
                    if (str_starts_with($path, 'shoots/') || str_starts_with($path, '/shoots/')) {
                        return url('storage/' . ltrim($path, '/'));
                    }

                    return url('storage/' . $path);
                };

                if ($needsWatermark) {
                    $thumbUrl = $resolveUrl($file['watermarked_thumbnail_path'] ?? $file['thumbnail_path'] ?? null);
                    $webUrl = $resolveUrl($file['watermarked_web_path'] ?? $file['web_path'] ?? null);
                    $placeholderUrl = $resolveUrl($file['watermarked_placeholder_path'] ?? $file['placeholder_path'] ?? null);

                    $file['thumbnail_url'] = $thumbUrl;
                    $file['thumb_url'] = $thumbUrl;
                    $file['thumb'] = $thumbUrl;
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
                } else {
                    $thumbUrl = $resolveUrl($file['thumbnail_path'] ?? null);
                    $webUrl = $resolveUrl($file['web_path'] ?? null);
                    $placeholderUrl = $resolveUrl($file['placeholder_path'] ?? null);

                    $file['thumbnail_url'] = $thumbUrl;
                    $file['thumb_url'] = $thumbUrl;
                    $file['thumb'] = $thumbUrl;
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

        return $shootArray;
    }

    public function transformShoot(Shoot $shoot): Shoot
    {
        $shoot->loadMissing(['client', 'photographer', 'editor', 'service', 'services.category', 'rep', 'createdByUser']);
        if (!$shoot->relationLoaded('files')) {
            $shoot->load(['files' => function ($query) {
                $query->select('id', 'shoot_id', 'workflow_stage', 'is_favorite', 'is_cover', 'flag_reason', 'url', 'path', 'dropbox_path');
            }]);
        }
        if (!$shoot->relationLoaded('payments')) {
            $shoot->load('payments');
        }
        $shoot->append('total_paid', 'remaining_balance', 'total_photographer_pay');

        if (!$shoot->payment_status || !in_array($shoot->payment_status, ['paid', 'unpaid', 'partial'], true)) {
            $totalPaid = $shoot->total_paid ?? 0;
            $totalQuote = $shoot->total_quote ?? 0;
            $shoot->payment_status = $this->calculatePaymentStatus($totalPaid, $totalQuote);
        }

        $shoot->setAttribute('service_subtotal', (float) (($shoot->base_quote ?? 0) + ($shoot->discount_amount ?? 0)));
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

        if ($isClientRole && $shoot->photographer) {
            $shoot->setAttribute('photographer', [
                'id' => $shoot->photographer->id,
                'name' => $shoot->photographer->name,
                'avatar' => $shoot->photographer->avatar ?? null,
            ]);
        }

        if ($shoot->client && !$isEditorRole) {
            if ($isPhotographerRole) {
                $clientData = [
                    'id' => $shoot->client->id,
                    'name' => $shoot->client->name,
                    'phonenumber' => $shoot->client->phonenumber ?? $shoot->client->phone ?? null,
                ];
            } else {
                $clientData = [
                    'id' => $shoot->client->id,
                    'name' => $shoot->client->name,
                    'email' => $shoot->client->email,
                    'company_name' => $shoot->client->company_name ?? $shoot->client->company ?? null,
                    'phonenumber' => $shoot->client->phonenumber ?? $shoot->client->phone ?? null,
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

                if (!$clientRep && $shoot->rep) {
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
        } elseif ($isEditorRole) {
            $shoot->setAttribute('client', null);
        }

        $shoot->package = [
            'name' => $shoot->package_name ?? optional($shoot->service)->name,
            'expectedDeliveredCount' => $shoot->expected_final_count,
            'bracketMode' => $shoot->bracket_mode,
            'servicesIncluded' => !empty($shoot->package_services_included)
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
        if (!$shoot->hero_image) {
            $shoot->hero_image = $this->resolveHeroImage($shoot, false);
        }
        $shoot->primary_action = $this->getPrimaryActionForRole(
            $shoot,
            auth()->user()->role ?? 'client'
        );

        $shoot->shoot_notes = $shoot->shoot_notes;
        $shoot->company_notes = $shoot->company_notes;
        $shoot->photographer_notes = $shoot->photographer_notes;
        $shoot->editor_notes = $shoot->editor_notes;

        $shoot->mls_id = $shoot->mls_id;
        $shoot->listing_source = $shoot->listing_source;
        $shoot->property_details = $shoot->property_details;
        $shoot->integration_flags = $shoot->integration_flags;
        $shoot->bright_mls_publish_status = $shoot->bright_mls_publish_status;
        $shoot->bright_mls_last_published_at = $shoot->bright_mls_last_published_at;
        $shoot->bright_mls_manifest_id = $shoot->bright_mls_manifest_id;
        $shoot->iguide_tour_url = $shoot->iguide_tour_url;
        $shoot->iguide_floorplans = $shoot->iguide_floorplans;
        $shoot->iguide_last_synced_at = $shoot->iguide_last_synced_at;
        $shoot->is_private_listing = $shoot->is_private_listing ?? false;
        $shoot->mmm_status = $shoot->mmm_status;
        $shoot->mmm_order_number = $shoot->mmm_order_number;
        $shoot->mmm_buyer_cookie = $shoot->mmm_buyer_cookie;
        $shoot->mmm_redirect_url = $shoot->mmm_redirect_url;
        $shoot->mmm_last_punchout_at = $shoot->mmm_last_punchout_at;
        $shoot->mmm_last_order_at = $shoot->mmm_last_order_at;
        $shoot->mmm_last_error = $shoot->mmm_last_error;
        $shoot->tour_links = $shoot->tour_links ?? [];

        try {
            if ($shoot->relationLoaded('services') && $shoot->services->isNotEmpty()) {
                $shootPhotographerId = $shoot->photographer_id;
                $shootPhotographer = $shoot->photographer;

                $servicePhotographerIds = $shoot->services
                    ->filter(fn ($s) => is_object($s))
                    ->map(fn ($s) => $s->pivot?->photographer_id)
                    ->filter()
                    ->unique()
                    ->values();
                $servicePhotographers = $servicePhotographerIds->isNotEmpty()
                    ? User::whereIn('id', $servicePhotographerIds)->get()->keyBy('id')
                    : collect();

                $transformedServices = $shoot->services->map(function ($service) use ($shootPhotographerId, $shootPhotographer, $servicePhotographers) {
                    if (is_array($service)) {
                        return $service;
                    }

                    $pivotPhotographerId = $service->pivot?->photographer_id ?? null;
                    $resolvedPhotographerId = $pivotPhotographerId ?? $shootPhotographerId;

                    $resolvedPhotographer = null;
                    if ($resolvedPhotographerId) {
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
                    $sqftRanges = $service->relationLoaded('sqftRanges')
                        ? $service->getRelation('sqftRanges')
                        : $service->sqftRanges()->get();

                    return [
                        'id' => (string) $service->id,
                        'name' => $service->name,
                        'price' => (float) ($service->pivot?->price ?? $service->price ?? 0),
                        'quantity' => (int) ($service->pivot?->quantity ?? 1),
                        'pricing_type' => $service->pricing_type,
                        'photo_count' => $service->photo_count !== null ? (int) $service->photo_count : null,
                        'sqft_ranges' => $sqftRanges->map(fn ($range) => [
                            'id' => $range->id,
                            'sqft_from' => (int) $range->sqft_from,
                            'sqft_to' => (int) $range->sqft_to,
                            'duration' => $range->duration !== null ? (int) $range->duration : null,
                            'price' => (float) $range->price,
                            'photographer_pay' => $range->photographer_pay !== null ? (float) $range->photographer_pay : null,
                            'photo_count' => $range->photo_count !== null ? (int) $range->photo_count : null,
                        ])->values()->all(),
                        'photographer_pay' => $service->pivot?->photographer_pay ? (float) $service->pivot->photographer_pay : null,
                        'photographer_id' => $pivotPhotographerId ? (string) $pivotPhotographerId : null,
                        'resolved_photographer_id' => $resolvedPhotographerId ? (string) $resolvedPhotographerId : null,
                        'photographer' => $resolvedPhotographer,
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
            \Log::warning('transformShoot services error for shoot ' . $shoot->id . ': ' . $e->getMessage());
        }

        $servicesArray = collect($shoot->getAttribute('services') ?? $shoot->services)->pluck('name')->filter()->values()->all();
        $miscItems = $this->getInvoiceMiscItemNames($shoot);
        if (!empty($miscItems)) {
            $servicesArray = array_merge($servicesArray, $miscItems);
        }

        $shoot->setAttribute('services_list', $servicesArray);
        $shoot->setAttribute('raw_photo_count', $shoot->raw_photo_count ?? 0);
        $shoot->setAttribute('edited_photo_count', $shoot->edited_photo_count ?? 0);
        $shoot->setAttribute('raw_missing_count', $shoot->raw_missing_count ?? 0);
        $shoot->setAttribute('edited_missing_count', $shoot->edited_missing_count ?? 0);
        $shoot->setAttribute('expected_raw_count', $shoot->expected_raw_count ?? 0);
        $shoot->setAttribute('missing_raw', (bool) $shoot->missing_raw);
        $shoot->setAttribute('missing_final', (bool) $shoot->missing_final);

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

    public function getInvoiceMiscItemNames(Shoot $shoot): array
    {
        $invoice = Invoice::where('shoot_id', $shoot->id)->first();
        if (!$invoice) {
            return [];
        }

        return $invoice->items()
            ->where('type', InvoiceItem::TYPE_EXPENSE)
            ->get()
            ->filter(function ($item) {
                $meta = is_array($item->meta) ? $item->meta : [];
                return ($meta['source'] ?? null) === 'admin_misc';
            })
            ->pluck('description')
            ->filter()
            ->values()
            ->all();
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
            $mediaType = strtolower($file->media_type ?? '');
            if ($mediaType === 'floorplan') {
                return true;
            }
            $filename = strtolower($file->filename ?? $file->stored_filename ?? $file->path ?? '');
            $floorplanPatterns = ['floorplan', 'floor-plan', 'floor_plan', 'fp_', 'fp-', 'layout', 'blueprint'];
            foreach ($floorplanPatterns as $pattern) {
                if (str_contains($filename, $pattern)) {
                    return true;
                }
            }

            return false;
        };

        $cover = $shoot->files->firstWhere('is_cover', true);
        if ($cover && !$isExcluded($cover)) {
            return $this->resolveOptimizedFileUrl($cover);
        }

        $withOptimized = $shoot->files->first(function ($file) use ($isExcluded) {
            return !$isExcluded($file) && (!empty($file->web_path) || !empty($file->thumbnail_path));
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
            return !$isExcluded($file);
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
        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        if ($totalPaid >= $totalQuote) {
            return 'paid';
        }

        return 'partial';
    }
}
