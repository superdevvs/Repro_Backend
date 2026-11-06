<?php

namespace App\Services\Shoots;

use App\Jobs\{FinalizeShootJob, ProcessProviderAutoDelivery};
use App\Models\{Shoot, ShootService, ShootFile};
use App\Services\{IguideService, CubiCasaService};
use App\Services\Media\MediaStorage;
use App\Support\{EditingDispatchWriteLock, LockedWrite};
use Illuminate\Support\Facades\{DB, Schema};

/** Verified provider observations and exact output approval; no human impersonation. */
class ProviderAutoDeliveryService
{
    public const MAX_LOCAL_VERIFICATION_BYTES = 64 * 1024 * 1024;
    public function parts(ShootService $item, ?string $provider = null): array
    {
        return array_values(array_filter(app(DeliveryComponentRequirements::class)->forItem($item), fn ($part) =>
            (($part['provider'] ?? null) === 'iguide' && in_array($part['kind'] ?? '', ['floorplan', 'tour'], true)
                || ($part['provider'] ?? null) === 'cubicasa' && ($part['kind'] ?? '') === 'floorplan')
            && (! $provider || $part['provider'] === $provider)));
    }

    public function scope(Shoot $shoot, ShootService $item, string $provider): ?Shoot
    {
        $view = app(ShootUnitTourScope::class)->forLine($shoot, $item->id);
        $identity = $view->getAttribute($provider === 'iguide' ? 'iguide_property_id' : 'cubicasa_order_id');
        if (! is_string($identity) || $identity === '') return null;
        $peers = app(ProviderAutoDeliverySettings::class)->supportedItems($shoot)->filter(fn ($peer) =>
            (int) $peer->shoot_unit_id === (int) $item->shoot_unit_id && $this->parts($peer, $provider) !== []);
        $pin = $item->shoot_unit_id ? data_get($item->unit?->provider_data, $provider.'_service_line_id')
            : ($provider === 'iguide' ? data_get($shoot->iguide_data, 'service_line_id') : null);
        if ($item->shoot_unit_id) $pin = data_get($item->unit?->provider_data, 'lines.'.$item->id.'.'.$provider.'_service_line_id', $pin);
        if ($pin && (int) $pin !== (int) $item->id) return null;
        if (! $pin && $peers->count() !== 1) return null;
        return $view;
    }

    public function blocked(Shoot $shoot, ?ShootService $item = null): bool
    {
        if ($shoot->auto_delivery_enabled === false || $shoot->isImportDraft() || $shoot->isInternalTestShoot()) return true;
        if (collect([$shoot->status, $shoot->workflow_status])->contains(fn ($status) => in_array(strtolower((string) $status),
            ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined', 'requested', 'import_draft'], true))) return true;
        if ($item && (! $item->is_deliverable || $item->cancelled_at || $item->workflow_status === 'cancelled' || $item->delivery_status === 'cancelled'
            || ($item->scheduled_at && app(\App\Services\Schedule\ScheduleInstantResolver::class)->forServiceItem($shoot, $item)?->isFuture()))) return true;
        return false;
    }

    /** Callers establish authentication from successful credentialed HTTP or validated webhook credentials. */
    public function observe(Shoot $scoped, string $provider, array $parsed, string $source, string $eventFingerprint, bool $queue = true): ?int
    {
        if (! in_array($provider, ['iguide', 'cubicasa'], true) || ! in_array($source, ['provider_api', 'authenticated_webhook'], true)
            || ! Schema::hasTable('shoot_provider_delivery_verifications')) return null;
        $root = Shoot::find($scoped->id);
        if (! $root) return null;
        $identity = $parsed[$provider === 'iguide' ? 'property_id' : 'order_id'] ?? null;
        if (! is_string($identity) || $identity === '') return null;
        $state = $provider === 'iguide' ? data_get($parsed, 'delivery_readiness.state') : (strtolower((string) ($parsed['status'] ?? '')) === 'ready' ? 'ready' : 'not_ready');
        if (! in_array($state, ['ready', 'not_ready'], true)) return null;
        $explicitLine = $scoped->relationLoaded('tourServiceLine') ? $scoped->getRelation('tourServiceLine')?->id : null;
        if (! $explicitLine && $provider === 'iguide' && ! $scoped->relationLoaded('tourUnit')) $explicitLine = data_get($root->iguide_data, 'service_line_id');
        $unitId = $scoped->relationLoaded('tourUnit') ? $scoped->getRelation('tourUnit')->id : null;
        $candidates = app(ProviderAutoDeliverySettings::class)->supportedItems($root)->filter(fn ($item) => $this->parts($item, $provider) !== []
            && (int) $item->shoot_unit_id === (int) $unitId && (! $explicitLine || (int) $item->id === (int) $explicitLine));
        if ($candidates->count() !== 1) return null;
        $item = $candidates->first(); $view = $this->scope($root, $item, $provider);
        if (! $view || $view->getAttribute($provider === 'iguide' ? 'iguide_property_id' : 'cubicasa_order_id') !== $identity) return null;
        $id = LockedWrite::run(fn () => DB::transaction(function () use ($root, $item, $provider, $identity, $state, $source, $parsed, $eventFingerprint) {
            EditingDispatchWriteLock::acquire($root->id);
            $fresh = Shoot::findOrFail($root->id); $line = $fresh->serviceItems()->findOrFail($item->id);
            $view = $this->scope($fresh, $line, $provider);
            if (! $view || $view->getAttribute($provider === 'iguide' ? 'iguide_property_id' : 'cubicasa_order_id') !== $identity) return null;
            $execution = app(DeliveryPlanService::class)->itemExecutionKey($fresh, $line);
            $apiFingerprint = null;
            if ($source === 'provider_api') {
                // A fresh credentialed HTTP read belongs to its current execution.
                // Repeated identical reads within it retain the first observation;
                // a later ready->not-ready->ready transition creates a new event.
                $apiFingerprint = hash('sha256', $eventFingerprint.':'.$execution);
                $latest = DB::table('shoot_provider_delivery_verifications')->where('shoot_service_id', $line->id)->where('provider', $provider)
                    ->where('execution_key', $execution)->orderByDesc('id')->first();
                if ($latest && $latest->source === 'provider_api' && data_get(json_decode($latest->evidence, true), 'api_fingerprint') === $apiFingerprint) return (int) $latest->id;
                $eventFingerprint = hash('sha256', $apiFingerprint.':after:'.($latest?->id ?? 0));
            }
            // Authenticated webhook identities remain globally first-seen so a
            // delayed replay cannot acquire freshness in a new execution.
            $existing = DB::table('shoot_provider_delivery_verifications')->where('shoot_service_id', $line->id)->where('provider', $provider)->where('event_fingerprint', $eventFingerprint)->first();
            if ($existing) return (int) $existing->id;
            return DB::table('shoot_provider_delivery_verifications')->insertGetId(['shoot_id' => $fresh->id, 'shoot_service_id' => $line->id,
                'provider' => $provider, 'execution_key' => $execution, 'identity_key' => $identity,
                'state' => $state, 'source' => $source, 'event_fingerprint' => $eventFingerprint,
                'evidence' => json_encode(['unit_id' => $line->shoot_unit_id, 'floorplans' => $parsed['floorplans'] ?? [], 'tour_url' => $parsed['tour_url'] ?? null,
                    'public_viewer' => $parsed['_public_viewer'] ?? null, 'api_fingerprint' => $apiFingerprint], JSON_THROW_ON_ERROR), 'verified_at' => now()]);
        }), 'provider.delivery-observation');
        if ($id) $this->rememberTransport($id, $provider, $identity, $parsed);
        if ($id && $queue) ProcessProviderAutoDelivery::dispatch($root->id)->afterCommit();
        return $id;
    }

    /** Credentials may rotate; delivery identity, state and first-seen time never do. */
    private function rememberTransport(int $id, string $provider, string $identity, array $parsed): void
    {
        DB::afterCommit(function () use ($id, $provider, $identity, $parsed) {
            $proof = DB::table('shoot_provider_delivery_verifications')->find($id);
            if (! $proof || $proof->provider !== $provider || $proof->identity_key !== $identity || $proof->state !== 'ready') return;
            $shoot = Shoot::find($proof->shoot_id); $item = $shoot?->serviceItems()->find($proof->shoot_service_id);
            if (! $shoot || ! $item || app(DeliveryPlanService::class)->itemExecutionKey($shoot, $item) !== $proof->execution_key) return;
            $view = $this->scope($shoot, $item, $provider);
            if (! $view || $view->getAttribute($provider === 'iguide' ? 'iguide_property_id' : 'cubicasa_order_id') !== $identity) return;
            $latest = DB::table('shoot_provider_delivery_verifications')->where('shoot_service_id', $item->id)->where('provider', $provider)
                ->where('execution_key', $proof->execution_key)->orderByDesc('id')->value('id');
            if ((int) $latest !== (int) $proof->id) return;
            $evidence = json_decode($proof->evidence, true);
            if ((int) ($evidence['unit_id'] ?? 0) !== (int) $item->shoot_unit_id) return;
            $manifest = $this->assetManifest($evidence['floorplans'] ?? []);
            $assets = $parsed['floorplans'] ?? [];
            if ($manifest === [] || $this->assetManifest($assets) !== $manifest) return;
            \Illuminate\Support\Facades\Cache::put($this->transportKey($proof), [
                'provider' => $provider, 'identity_key' => $identity, 'execution_key' => $proof->execution_key,
                'unit_id' => $item->shoot_unit_id, 'manifest' => $manifest, 'floorplans' => $assets,
            ], now()->addMinutes(10));
        });
    }

    private function transportKey(object $proof): string
    {
        return 'provider:delivery:transport:'.$proof->id.':'.hash('sha256', $proof->execution_key);
    }

    /** Use renewed credentials only for the exact immutable authenticated manifest. */
    private function transportAssets(object $proof, ?array $fresh = null): array
    {
        $evidence = json_decode($proof->evidence, true);
        $original = $evidence['floorplans'] ?? [];
        $manifest = $this->assetManifest($original);
        if ($fresh !== null && $manifest !== [] && $this->assetManifest($fresh) === $manifest) return $fresh;
        $transport = \Illuminate\Support\Facades\Cache::get($this->transportKey($proof));
        if (is_array($transport) && ($transport['provider'] ?? null) === $proof->provider
            && ($transport['identity_key'] ?? null) === $proof->identity_key && ($transport['execution_key'] ?? null) === $proof->execution_key
            && (int) ($transport['unit_id'] ?? 0) === (int) ($evidence['unit_id'] ?? 0)
            && ($transport['manifest'] ?? null) === $manifest && $this->assetManifest($transport['floorplans'] ?? []) === $manifest) return $transport['floorplans'];
        return $original;
    }

    public function canonicalFingerprint(string $provider, array $parsed): string
    {
        unset($parsed['raw_data'], $parsed['_generated_tour_urls']);
        if (isset($parsed['delivery_readiness'])) unset($parsed['delivery_readiness']['verified_at']);
        if (isset($parsed['_public_viewer'])) unset($parsed['_public_viewer']['checked_at']);
        return hash('sha256', $provider.json_encode($this->stablePayload($parsed), JSON_THROW_ON_ERROR));
    }

    public function webhookFingerprint(array $raw): string
    {
        return hash('sha256', json_encode($this->stablePayload($raw), JSON_THROW_ON_ERROR));
    }

    private function stablePayload(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $entry) {
                if (in_array(strtolower((string) $key), ['authtoken', 'auth_token', 'access_token', 'signature', 'token', 'webhooktoken', 'webhooksecret'], true)) continue;
                $result[$key] = $this->stablePayload($entry);
            }
            if (! array_is_list($result)) ksort($result);
            return $result;
        }
        if (is_string($value) && preg_match('#^https?://#i', $value)) {
            return ProviderDeliveryUrl::canonical($value);
        }
        return $value;
    }

    /** Bounded local-only evidence; safe to recheck without remote I/O under the writer. */
    public function localOutputSnapshot(ShootFile $file): ?array
    {
        $media = app(MediaStorage::class);
        if ($media->readFromR2Enabled() || $media->r2Only()) return null;
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $key = $file->storage_path ?: $file->path;
        if (! $key || ! $file->web_path) return null;
        $local = $media->absolutePath($key); $preview = $media->absolutePath($file->web_path);
        if (! $local || ! $preview || preg_match('#^[a-z]+://#i', $local) || preg_match('#^[a-z]+://#i', $preview)) return null;
        clearstatcache(true, $local); clearstatcache(true, $preview);
        if (! is_file($local) || ! is_file($preview) || ! is_readable($local) || ! is_readable($preview)) return null;
        $size = filesize($local); $previewSize = filesize($preview);
        if (! $size || ! $previewSize || $size + $previewSize > self::MAX_LOCAL_VERIFICATION_BYTES
            || ! in_array($finfo->file($local), ['application/pdf', 'image/jpeg', 'image/png'], true)
            || ! in_array($finfo->file($preview), ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
        $hash = hash_file('sha256', $local); $previewHash = hash_file('sha256', $preview);
        if (! $hash || ! $previewHash) return null;
        return ['local_path' => $local, 'local_preview_path' => $preview, 'local_bytes' => $size, 'local_preview_bytes' => $previewSize,
            'sha256' => $hash, 'preview_sha256' => $previewHash];
    }

    public function assetManifest(array $assets): array
    {
        $result = [];
        foreach ($assets as $asset) {
            if (($asset['component_kind'] ?? 'floorplan') !== 'floorplan' || str_starts_with((string) ($asset['asset_key'] ?? ''), 'pdf_home_report_')) continue;
            $type = (string) ($asset['type'] ?? pathinfo(parse_url($asset['url'] ?? '', PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if (! preg_match('/^(pdf|jpe?g|png)$/i', $type) || empty($asset['asset_key']) || empty($asset['url'])) continue;
            $result[] = ['key' => (string) $asset['asset_key'], 'url' => ProviderDeliveryUrl::canonical($asset['url'])];
        }
        usort($result, fn ($a, $b) => [$a['key'], $a['url']] <=> [$b['key'], $b['url']]);
        return $result;
    }

    private function requestRecovery(Shoot $shoot, ShootService $item, string $provider, array $assets): void
    {
        $job = $provider === 'iguide' ? new \App\Jobs\IngestIguideAssetsJob($shoot->id, $assets, $item->id, true)
            : new \App\Jobs\IngestCubiCasaAssetsJob($shoot->id, $assets, $item->id, true);
        dispatch($job)->afterCommit();
    }

    /** Network and storage checks happen before reserving SQLite's writer. */
    public function run(int $shootId): void
    {
        $shoot = Shoot::find($shootId);
        if (! $shoot || $this->blocked($shoot)) return;
        $planner = app(DeliveryPlanService::class); $plan = $planner->plan($shoot, null); $prepared = []; $preparedBytes = 0;
        foreach (app(ProviderAutoDeliverySettings::class)->supportedItems($shoot) as $item) {
            if ($this->blocked($shoot, $item)) continue;
            foreach (array_unique(array_column($this->parts($item), 'provider')) as $provider) {
                $unresolved = collect($plan['components'])->where('shoot_service_id', $item->id)->where('resolved', false)
                    ->filter(fn ($component) => collect($this->parts($item, $provider))->contains('key', explode(':', $component['id'], 2)[1]));
                if ($unresolved->isEmpty() || ! ($view = $this->scope($shoot, $item, $provider))) continue;
                $view->setRelation('tourServiceLine', $item);
                $identity = $view->getAttribute($provider === 'iguide' ? 'iguide_property_id' : 'cubicasa_order_id');
                $execution = $planner->itemExecutionKey($shoot, $item);
                $prior = DB::table('shoot_provider_delivery_verifications')->where('shoot_service_id', $item->id)->where('provider', $provider)
                    ->where('execution_key', $execution)->where('identity_key', $identity)->orderByDesc('id')->first();
                if ($provider === 'cubicasa') {
                    $raw = app(CubiCasaService::class)->getOrder($identity);
                    if (! $raw || ($raw['id'] ?? null) !== $identity) throw new \RuntimeException('Current CubiCasa delivery verification is unavailable.');
                    $parsed = app(CubiCasaService::class)->parseOrderData($raw);
                    $id = $this->observe($view, $provider, $parsed, 'provider_api', $this->canonicalFingerprint($provider, $parsed), false);
                    if (strtolower((string) ($parsed['status'] ?? '')) !== 'ready') continue;
                } else {
                    $parsed = app(IguideService::class)->syncProperty($identity);
                    if ($parsed && ($parsed['property_id'] ?? null) !== $identity) continue;
                    if ($parsed && data_get($parsed, 'delivery_readiness.state') === 'not_ready') {
                        $this->observe($view, $provider, $parsed, 'provider_api', $this->canonicalFingerprint($provider, $parsed), false); continue;
                    }
                    if (! $parsed && (! $prior || $prior->state !== 'ready' || $prior->source !== 'authenticated_webhook')) continue;
                    $url = $parsed['tour_url'] ?? $view->iguide_tour_url;
                    $viewer = is_string($url) ? app(IguidePublicViewerVerification::class)->verify($url, $identity) : null;
                    if (! $viewer) continue;
                    $cutoff = data_get($item->delivery_requirements, '0.execution_started_at') ?: (app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot) ? $shoot->active_work_started_at : $shoot->created_at);
                    if (\Carbon\Carbon::parse($viewer['updated_at'])->lt($cutoff)) continue;
                    if ($parsed) {
                        $parsed['delivery_readiness'] = ['state' => 'ready']; $parsed['_public_viewer'] = $viewer;
                        if (empty($parsed['floorplans']) && $prior) $parsed['floorplans'] = $this->transportAssets($prior);
                        $id = $this->observe($view, $provider, $parsed, 'provider_api', $this->canonicalFingerprint($provider, $parsed), false);
                    } else $id = (int) $prior->id;
                }
                if (empty($id)) continue;
                $proof = DB::table('shoot_provider_delivery_verifications')->find($id);
                if (! $proof || $proof->execution_key !== $execution || $proof->state !== 'ready') continue;
                $evidence = json_decode($proof->evidence, true);
                $assets = $this->transportAssets($proof, $parsed['floorplans'] ?? null);
                $files = app(ServiceDeliveryEligibility::class)->currentCycleFiles($shoot, $shoot->files()->where('shoot_service_id', $item->id)->get());
                foreach ($unresolved as $component) {
                    $selection = ['verification_id' => $id, 'component_id' => $component['id'], 'provider' => $provider, 'identity_key' => $identity,
                        'execution_key' => $execution, 'files' => [], 'public_viewer' => $provider === 'iguide' ? ($viewer ?? null) : null];
                    if ($component['kind'] === 'floorplan') {
                        $manifest = $this->assetManifest($evidence['floorplans'] ?? []);
                        $expected = collect($assets)->filter(fn ($asset) => in_array(['key' => (string) ($asset['asset_key'] ?? ''),
                            'url' => ProviderDeliveryUrl::canonical($asset['url'] ?? '')], $manifest, true));
                        if ($expected->isEmpty()) continue;
                        $selection['expected_assets'] = $this->assetManifest($evidence['floorplans'] ?? []);
                        foreach ($expected as $asset) {
                            $file = $files->first(fn ($candidate) => data_get($candidate->metadata, 'source') === $provider
                                && data_get($candidate->metadata, $provider.'_asset_key') === ($asset['asset_key'] ?? null)
                                && $candidate->scan_status === ShootFile::SCAN_STATUS_CLEAN && $planner->usableOutput($candidate)
                                && $planner->contentKind($candidate) === 'floorplan' && $candidate->web_path);
                            if (! $file || ! ($snapshot = $this->localOutputSnapshot($file))) {
                                $this->requestRecovery($shoot, $item, $provider, $assets); $selection['files'] = []; break;
                            }
                            $preparedBytes += $snapshot['local_bytes'] + $snapshot['local_preview_bytes'];
                            if ($preparedBytes > self::MAX_LOCAL_VERIFICATION_BYTES) return;
                            $sameSource = ProviderDeliveryUrl::canonical((string) data_get($file->metadata, 'original_url')) === ProviderDeliveryUrl::canonical($asset['url']);
                            $boundIdentity = data_get($file->metadata, 'provider_identity');
                            $download = \Illuminate\Support\Facades\Http::timeout(60)->get($asset['url']);
                            if (! $download->successful() || $download->body() === '') throw new \RuntimeException('Current provider asset verification is unavailable.');
                            $digest = hash('sha256', $download->body());
                            if (! $sameSource || ($boundIdentity !== null && $boundIdentity !== $identity) || ! hash_equals($digest, $snapshot['sha256'])) {
                                $this->requestRecovery($shoot, $item, $provider, $assets); $selection['files'] = []; break;
                            }
                            $selection['files'][] = ['id' => $file->id, 'version' => (int) ($file->content_version ?: 1), 'path' => $file->path,
                                'storage_path' => $file->storage_path, 'web_path' => $file->web_path, 'updated_at' => $file->getRawOriginal('updated_at'),
                                'metadata_hash' => hash('sha256', json_encode($file->metadata)), 'asset_key' => $asset['asset_key'],
                                'original_url' => ProviderDeliveryUrl::canonical($asset['url'])] + $snapshot;
                        }
                        if ($selection['files'] === []) continue;
                    } elseif ($component['kind'] !== 'tour' || $provider !== 'iguide' || empty($selection['public_viewer'])) continue;
                    $prepared[] = $selection;
                }
            }
        }
        if ($prepared !== []) FinalizeShootJob::forProvider($shootId, $prepared)->handle(app(\App\Services\ShootActivityLogger::class));
    }
}
