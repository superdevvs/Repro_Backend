<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Models\ListingStudioGuestTrial;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\StudioProviderSettings;
use App\Services\Studio\VirtualStagingOptions;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListingStudioGuestTrialService
{
    public const SERVICES = ['staging' => 'Virtual staging', 'decluttering' => 'Digital decluttering', 'grass' => 'Green grass', 'twilight' => 'Digital twilight'];

    public const HELD = ['queued', 'processing', 'needs_attention', 'completed'];

    public const DISK = 'listing_studio_trials';

    public function capabilities(string $sessionHash): array
    {
        $allowances = $this->allowances($sessionHash);
        $enabled = $this->enabled();
        $providers = app(StudioProviderSettings::class);
        $services = [];
        foreach (self::SERVICES as $id => $label) {
            $route = $this->providerService($id);
            $ready = $providers->readiness($route, $providers->route($route));
            $services[] = ['id' => $id, 'label' => $label, 'ready' => $ready['ready'], 'reason' => $ready['reason'] ?? null,
                'available' => $enabled && $ready['ready'] && $allowances[$id]];
        }

        return ['enabled' => $enabled, 'services' => $services,
            'turnstileSiteKey' => $enabled ? config('listing-studio-trials.turnstile_site_key') : null,
            'limits' => ['maxFileBytes' => config('listing-studio-trials.max_file_kb') * 1024,
                'globalDaily' => config('listing-studio-trials.global_daily_limit'), 'ipDaily' => config('listing-studio-trials.ip_daily_limit')],
            'stagingOptions' => ['rooms' => VirtualStagingOptions::ROOMS, 'styles' => VirtualStagingOptions::STYLES],
            'allowances' => $allowances, 'activeTrialId' => $this->activeTrialId($sessionHash)];
    }

    public function enabled(): bool
    {
        return config('listing-studio-trials.enabled')
            && strlen((string) config('listing-studio-trials.bridge_key')) >= 32
            && filled(config('listing-studio-trials.turnstile_secret'))
            && filled(config('listing-studio-trials.turnstile_site_key'))
            && count(config('listing-studio-trials.turnstile_hosts', [])) > 0;
    }

    public function start(string $sessionHash, string $ipHash, string $ip, string $key, UploadedFile $image, array $input): ListingStudioGuestTrial
    {
        $this->validateImage($image);
        $options = $input['service'] === 'staging'
            ? ['roomType' => $input['roomType'] ?? 'living', 'style' => $input['style'] ?? 'modern'] : [];
        $requestHash = hash('sha256', json_encode([$input['service'], $options, hash_file('sha256', $image->getRealPath())], JSON_THROW_ON_ERROR));
        if ($existing = ListingStudioGuestTrial::where('session_hash', $sessionHash)->where('idempotency_key', $key)->first()) {
            return $this->replay($existing, $requestHash);
        }
        if (! $this->enabled()) {
            throw new PublicApiException('Live photo editing is temporarily unavailable. You can still watch the sample walkthrough.', 'trial_unavailable', 503);
        }
        $providers = app(StudioProviderSettings::class);
        $providerService = $this->providerService($input['service']);
        if (! ($providers->readiness($providerService, $providers->route($providerService))['ready'] ?? false)) {
            throw new PublicApiException('This editing service is temporarily unavailable.', 'trial_service_unavailable', 503);
        }
        $this->verifyChallenge($input['challengeToken'], $ip);
        [$trial, $created] = $this->write(function () use ($sessionHash, $ipHash, $key, $requestHash, $input, $options, $image) {
            if ($existing = ListingStudioGuestTrial::where('session_hash', $sessionHash)->where('idempotency_key', $key)->first()) {
                return [$this->replay($existing, $requestHash), false];
            }
            $session = DB::table('listing_studio_guest_sessions')->where('session_hash', $sessionHash)->first();
            if ($session?->claimed_at && ! $session?->client_id) {
                throw new PublicApiException('This trial session is no longer available.', 'trial_session_closed', 403);
            }
            if ($session?->client_id) {
                $client = User::find($session->client_id);
                if (! $client) {
                    throw new PublicApiException('This trial session is no longer available.', 'trial_session_closed', 403);
                }
                $this->authorizeClient($client);
            }
            if ($this->activeTrialId($sessionHash)) {
                throw new PublicApiException('Please wait for your current edit to finish.', 'trial_already_processing', 409);
            }
            if (! $this->allowances($sessionHash)[$input['service']]) {
                throw new PublicApiException('Your free edit for this service has already been used.', 'trial_allowance_used', 409);
            }
            $window = now()->subDay();
            if (ListingStudioGuestTrial::where('started_at', '>', $window)->count() >= max(0, (int) config('listing-studio-trials.global_daily_limit'))) {
                throw new PublicApiException('Today’s free editing capacity has been reached. Please try again later.', 'trial_daily_limit', 429);
            }
            if (ListingStudioGuestTrial::where('ip_hash', $ipHash)->where('started_at', '>', $window)->count() >= max(0, (int) config('listing-studio-trials.ip_daily_limit'))) {
                throw new PublicApiException('The free editing limit for this connection has been reached. Please try again later.', 'trial_ip_limit', 429);
            }
            DB::table('listing_studio_guest_sessions')->insertOrIgnore(['session_hash' => $sessionHash, 'created_at' => now(), 'updated_at' => now()]);
            $id = (string) Str::uuid();
            $extension = match ($image->getMimeType()) {
                'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
            };

            return [ListingStudioGuestTrial::create([
                'id' => $id, 'session_hash' => $sessionHash, 'ip_hash' => $ipHash, 'client_id' => $session?->client_id,
                'service' => $input['service'], 'status' => 'queued', 'idempotency_key' => $key, 'request_hash' => $requestHash,
                'source_path' => 'trials/'.$id.'/source.'.$extension, 'source_mime' => $image->getMimeType(), 'source_name' => 'Original photo.'.$extension,
                'options' => $options, 'started_at' => now(), 'claimed_at' => $session?->claimed_at,
                'expires_at' => now()->addDays((int) config('listing-studio-trials.retention_days', 7)),
            ]), true];
        });
        if (! $created) {
            return $trial;
        }
        try {
            $stream = fopen($image->getRealPath(), 'rb');
            try {
                if (! Storage::disk(self::DISK)->put($trial->source_path, $stream)) {
                    throw new \RuntimeException('Private trial source write failed.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $this->createPreview($trial, $trial->source_path, 'original-preview.jpg');
            app(ListingStudioTrialPipeline::class)->start($trial);
        } catch (\Throwable $exception) {
            $trial->refresh();
            // Once linked, dispatch may have succeeded despite an interrupted response.
            // Hold the reservation for recovery rather than starting another provider job.
            $trial->update(['status' => $trial->workspace_id ? 'needs_attention' : 'failed', 'error_code' => $trial->workspace_id ? 'processing_uncertain' : 'upload_failed']);
            report($exception);
        }

        return $trial->fresh();
    }

    private function validateImage(UploadedFile $file): void
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] < 1 || $info[1] < 1 || (int) config('listing-studio-trials.max_pixels', 40000000) < $info[0] * $info[1]) {
            throw new PublicApiException('Upload a valid JPEG, PNG, or WebP image up to 40 megapixels.', 'trial_image_invalid', 422);
        }
    }

    private function verifyChallenge(string $token, string $ip): void
    {
        try {
            $response = Http::asForm()->timeout(15)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('listing-studio-trials.turnstile_secret'), 'response' => $token, 'remoteip' => $ip,
            ]);
            $data = $response->successful() ? $response->json() : [];
        } catch (\Throwable) {
            throw new PublicApiException('The bot check is temporarily unavailable. Please try again.', 'trial_challenge_unavailable', 503);
        }
        if (! is_array($data) || ($data['success'] ?? false) !== true || ($data['action'] ?? null) !== 'listing_studio_trial'
            || ! in_array($data['hostname'] ?? '', config('listing-studio-trials.turnstile_hosts', []), true)) {
            throw new PublicApiException('Please complete the bot check and try again.', 'trial_challenge_failed', 422);
        }
    }

    public function syncWorkspace(StudioWorkspace $workspace): void
    {
        $trial = ListingStudioGuestTrial::find($workspace->listing_studio_trial_id);
        if (! $trial || $trial->workspace_id !== $workspace->id || $trial->hasExpired()) {
            return;
        }
        if (in_array($workspace->status, ['preparing', 'generating'], true)) {
            $this->updateLiveTrial($trial, ['status' => filled($workspace->operation['trialStartedAt'] ?? null) ? 'processing' : 'queued']);

            return;
        }
        if ($workspace->status === 'completed') {
            $output = collect($workspace->outputs ?? [])->first(fn ($item) => ($item['kind'] ?? null) === 'image' && ($item['status'] ?? null) === 'completed');
            $path = $output['path'] ?? null;
            if (! $path || ! $this->outputPathValid($trial, $path) || ! Storage::disk(self::DISK)->exists($path)) {
                $this->updateLiveTrial($trial, ['status' => 'needs_attention', 'error_code' => 'output_unavailable']);

                return;
            }
            try {
                $preview = $this->createPreview($trial, $path);
            } catch (\Throwable $exception) {
                $this->updateLiveTrial($trial, ['status' => 'needs_attention', 'error_code' => 'output_unavailable']);
                report($exception);

                return;
            }
            $this->write(function () use ($trial, $path, $preview) {
                $fresh = $trial->fresh();
                if ($fresh && ! $fresh->hasExpired()) {
                    $fresh->update(['status' => 'completed', 'output_path' => $path, 'preview_path' => $preview, 'completed_at' => $fresh->completed_at ?? now(), 'error_code' => null]);
                }
            });
        } elseif (in_array($workspace->status, ['failed', 'cancelled'], true)) {
            $ambiguous = (bool) ($workspace->operation['trialFailureAmbiguous'] ?? false);
            $this->updateLiveTrial($trial, ['status' => $ambiguous ? 'needs_attention' : 'failed', 'error_code' => $ambiguous ? 'processing_uncertain' : 'processing_failed']);
        }
    }

    private function createPreview(ListingStudioGuestTrial $trial, string $path, string $filename = 'preview.jpg'): string
    {
        $bytes = Storage::disk(self::DISK)->get($path);
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] * $info[1] > 40000000 || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \RuntimeException('Trial output is not a supported image.');
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw new \RuntimeException('Trial output could not be decoded.');
        }
        $scale = min(1, 1024 / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $preview = imagecreatetruecolor($width, $height);
        try {
            imagefill($preview, 0, 0, imagecolorallocate($preview, 255, 255, 255));
            imagecopyresampled($preview, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            $label = 'REPRO - PREVIEW';
            $labelWidth = imagefontwidth(5) * strlen($label);
            for ($y = max(8, (int) ($height / 4)); $y < $height; $y += max(60, (int) ($height / 3))) {
                $x = max(0, (int) (($width - $labelWidth) / 2));
                imagefilledrectangle($preview, $x - 6, $y - 5, min($width, $x + $labelWidth + 6), $y + 20, imagecolorallocatealpha($preview, 0, 0, 0, 48));
                imagestring($preview, 5, $x, $y, $label, imagecolorallocatealpha($preview, 255, 255, 255, 14));
            }
            ob_start();
            try {
                imagejpeg($preview, null, 80);
                $result = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $previewPath = 'trials/'.$trial->id.'/'.$filename;
            if (! is_string($result)) {
                throw new \RuntimeException('Trial preview could not be stored.');
            }
            $this->write(function () use ($trial, $previewPath, $result) {
                $fresh = $trial->fresh();
                if (! $fresh || $fresh->hasExpired()) {
                    throw new PublicApiException('This trial result has expired.', 'trial_expired', 410);
                }
                if (! Storage::disk(self::DISK)->put($previewPath, $result)) {
                    throw new \RuntimeException('Trial preview could not be stored.');
                }
            });

            return $previewPath;
        } finally {
            imagedestroy($source);
            imagedestroy($preview);
        }
    }

    public function outputPathValid(ListingStudioGuestTrial $trial, string $path): bool
    {
        return $trial->workspace_id && str_starts_with($path, 'studio/workspaces/'.$trial->workspace_id.'/')
            && ! str_contains($path, '..') && ! str_contains($path, '\\') && ! str_contains($path, ':');
    }

    public function sessionTrials(string $sessionHash): array
    {
        return ['trials' => ListingStudioGuestTrial::where('session_hash', $sessionHash)->latest()->limit(100)->get()->map(fn ($trial) => $this->present($trial))->all(),
            'allowances' => $this->allowances($sessionHash), 'activeTrialId' => $this->activeTrialId($sessionHash)];
    }

    public function ownedGuestTrial(string $id, string $sessionHash): ListingStudioGuestTrial
    {
        $trial = ListingStudioGuestTrial::whereKey($id)->where('session_hash', $sessionHash)->firstOrFail();
        if ($trial->hasExpired()) {
            throw new PublicApiException('This trial result has expired.', 'trial_expired', 410);
        }

        return $trial;
    }

    public function allowances(string $sessionHash): array
    {
        $clientId = DB::table('listing_studio_guest_sessions')->where('session_hash', $sessionHash)->value('client_id');
        $used = ListingStudioGuestTrial::where(function ($query) use ($sessionHash, $clientId) {
            $query->where('session_hash', $sessionHash);
            if ($clientId) {
                $query->orWhere('client_id', $clientId);
            }
        })->where(function ($query) {
            $query->whereIn('status', self::HELD)->orWhereNotNull('completed_at');
        })->pluck('service')->all();

        return array_map(fn ($id) => ! in_array($id, $used, true), array_combine(array_keys(self::SERVICES), array_keys(self::SERVICES)));
    }

    private function activeTrialId(string $sessionHash): ?string
    {
        return ListingStudioGuestTrial::where('session_hash', $sessionHash)->whereIn('status', ['queued', 'processing', 'needs_attention'])
            ->where(function ($query) {
                $query->whereNotNull('client_id')->orWhere('expires_at', '>', now());
            })->value('id');
    }

    public function present(ListingStudioGuestTrial $trial, bool $owner = false): array
    {
        $expired = $trial->hasExpired();
        $completed = ! $expired && $trial->status === 'completed' && filled($trial->output_path);

        return ['id' => $trial->id, 'service' => $trial->service, 'status' => $expired ? 'expired' : $trial->status,
            'progress' => $trial->status === 'processing' ? $trial->workspace?->progress : null,
            'previewAvailable' => $completed && filled($trial->preview_path), 'downloadAvailable' => $owner && $completed,
            'originalPreviewAvailable' => ! $expired && Storage::disk(self::DISK)->exists('trials/'.$trial->id.'/original-preview.jpg'),
            'createdAt' => $trial->created_at?->toIso8601String(), 'completedAt' => $trial->completed_at?->toIso8601String(),
            'expiresAt' => $trial->client_id ? null : $trial->expires_at?->toIso8601String(),
            'errorCode' => $trial->error_code, 'error' => match ($trial->error_code) {
                'processing_uncertain' => 'This edit needs recovery. We will not start another paid request automatically.',
                'output_unavailable' => 'The edited file needs recovery. Please try viewing this result later.',
                'upload_failed' => 'The photo could not be saved. Please upload it again.',
                'processing_failed' => 'This edit could not be completed. Your free service allowance is available again.',
                default => null,
            }];
    }

    public function claimToken(ListingStudioGuestTrial $trial): array
    {
        if ($trial->status !== 'completed') {
            throw new PublicApiException('Wait for this edit to finish before downloading.', 'trial_not_ready', 409);
        }
        $token = bin2hex(random_bytes(32));
        $expires = now()->addMinutes((int) config('listing-studio-trials.claim_minutes', 30));
        $this->write(function () use ($trial, $token, $expires) {
            DB::table('listing_studio_guest_claim_tokens')->insert([
                'token_hash' => hash('sha256', $token), 'trial_id' => $trial->id, 'session_hash' => $trial->session_hash, 'expires_at' => $expires,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return ['token' => $token, 'expiresAt' => $expires->toIso8601String(), 'claimUrl' => 'https://reprodashboard.com/listing-studio/claim#token='.$token];
    }

    public function claim(User $client, string $token): array
    {
        $this->authorizeClient($client);
        $this->write(function () use ($client, $token) {
            $client = User::findOrFail($client->id);
            $this->authorizeClient($client);
            $claim = DB::table('listing_studio_guest_claim_tokens')->where('token_hash', hash('sha256', $token))->first();
            if (! $claim) {
                throw new PublicApiException('This download link has expired. Return to your preview and open Download again.', 'claim_expired', 410);
            }
            if ($claim->claimed_at) {
                if ((int) $claim->client_id !== $client->id) {
                    $this->claimConflict();
                }

                return;
            }
            if (now()->greaterThan($claim->expires_at)) {
                throw new PublicApiException('This download link has expired. Return to your preview and open Download again.', 'claim_expired', 410);
            }
            $session = DB::table('listing_studio_guest_sessions')->where('session_hash', $claim->session_hash)->first();
            if (! $session || ($session->claimed_at && (int) $session->client_id !== $client->id)) {
                $this->claimConflict();
            }
            $trials = ListingStudioGuestTrial::where('session_hash', $claim->session_hash)->get();
            $requested = $trials->firstWhere('id', $claim->trial_id);
            if (! $requested || $requested->hasExpired()) {
                throw new PublicApiException('This trial result has expired.', 'trial_expired', 410);
            }
            if ($requested->status !== 'completed') {
                throw new PublicApiException('The edited file is not ready yet.', 'trial_not_ready', 409);
            }
            // Old expired attempts do not block saving a newly completed service.
            // Attach their history too, so expiry cannot restore consumed allowances.
            $heldServices = $trials->filter(fn ($trial) => ! $trial->hasExpired() && (in_array($trial->status, self::HELD, true) || $trial->completed_at))->pluck('service');
            $duplicate = ListingStudioGuestTrial::where('client_id', $client->id)->where('session_hash', '!=', $claim->session_hash)
                ->whereIn('service', $heldServices)->where(fn ($query) => $query->whereIn('status', self::HELD)->orWhereNotNull('completed_at'))->exists();
            if ($duplicate) {
                throw new PublicApiException('This account has already used a free edit for one of these services. Your previous results are available in your account.', 'trial_allowance_used', 409);
            }
            ListingStudioGuestTrial::where('session_hash', $claim->session_hash)->whereNull('client_id')->where('expires_at', '<=', now())->update(['status' => 'expired']);
            DB::table('listing_studio_guest_sessions')->where('session_hash', $claim->session_hash)->update(['client_id' => $client->id, 'claimed_at' => $session->claimed_at ?? now(), 'updated_at' => now()]);
            ListingStudioGuestTrial::where('session_hash', $claim->session_hash)->update(['client_id' => $client->id, 'claimed_at' => now(), 'updated_at' => now()]);
            DB::table('listing_studio_guest_claim_tokens')->where('token_hash', hash('sha256', $token))->update(['client_id' => $client->id, 'claimed_at' => now(), 'updated_at' => now()]);
        });

        return $this->clientTrials($client);
    }

    private function claimConflict(): never
    {
        throw new PublicApiException('These trial results have already been saved to another account.', 'claim_conflict', 409);
    }

    public function authorizeClient(User $client): void
    {
        if ($client->role !== 'client' || ! $client->isAccountEligibleForAuthentication()) {
            throw new PublicApiException('Sign in to an active client account to download your result.', 'account_ineligible', 403);
        }
    }

    public function clientTrials(User $client): array
    {
        $this->authorizeClient($client);

        return ['trials' => ListingStudioGuestTrial::where('client_id', $client->id)->latest()->limit(100)->get()->map(fn ($trial) => $this->present($trial, true))->all()];
    }

    public function expireUnclaimed(int $limit = 100): int
    {
        $trials = ListingStudioGuestTrial::whereNull('media_purged_at')->where(function ($query) {
            $query->where('status', 'expired')->orWhere(fn ($unclaimed) => $unclaimed->whereNull('client_id')->where('expires_at', '<=', now()));
        })->orderBy('expires_at')->limit($limit)->get();
        $count = 0;
        foreach ($trials as $trial) {
            $expired = $this->write(function () use ($trial) {
                $fresh = $trial->fresh();
                if (! $fresh || ($fresh->status !== 'expired' && ($fresh->client_id || $fresh->expires_at->isFuture()))) {
                    return false;
                }
                $fresh->update(['status' => 'expired']);
                if ($fresh->workspace_id) {
                    StudioWorkspace::whereKey($fresh->workspace_id)->where('listing_studio_trial_id', $fresh->id)
                        ->update(['status' => 'cancelled', 'error' => 'Guest trial expired.']);
                }

                return true;
            });
            if (! $expired) {
                continue;
            }
            Storage::disk(self::DISK)->deleteDirectory('trials/'.$trial->id);
            if ($trial->workspace_id) {
                Storage::disk(self::DISK)->deleteDirectory('studio/workspaces/'.$trial->workspace_id);
            }
            $trial->update(['media_purged_at' => now()]);
            $count++;
        }
        DB::table('listing_studio_guest_claim_tokens')->whereNull('claimed_at')->where('expires_at', '<', now()->subDay())->delete();

        return $count;
    }

    /** Recover bookkeeping after a process died before/after queue publication, never resubmit providers. */
    public function reconcileStale(int $limit = 100): int
    {
        $count = 0;
        $stale = ListingStudioGuestTrial::whereIn('status', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(10))->limit($limit)->get();
        foreach ($stale as $trial) {
            if ($trial->workspace_id) {
                $workspace = $trial->workspace;
                if ($workspace && in_array($workspace->status, ['completed', 'failed', 'cancelled'], true)) {
                    $this->syncWorkspace($workspace);
                    $count++;
                } elseif (! $workspace || $workspace->updated_at->lt(now()->subMinutes(150))) {
                    $this->updateLiveTrial($trial, ['status' => 'needs_attention', 'error_code' => 'processing_uncertain']);
                    $count++;
                }
            } else {
                $this->write(function () use ($trial) {
                    $fresh = $trial->fresh();
                    if ($fresh && ! $fresh->workspace_id && $fresh->status === 'queued') {
                        // No workspace means no provider operation was ever constructed.
                        $fresh->update(['status' => 'failed', 'error_code' => 'upload_failed']);
                    }
                });
                $count++;
            }
        }

        return $count;
    }

    private function replay(ListingStudioGuestTrial $trial, string $hash): ListingStudioGuestTrial
    {
        if (! hash_equals($trial->request_hash, $hash)) {
            throw new PublicApiException('This edit request was already used for a different photo or service.', 'idempotency_conflict', 409);
        }

        return $trial;
    }

    private function updateLiveTrial(ListingStudioGuestTrial $trial, array $attributes): void
    {
        $this->write(function () use ($trial, $attributes) {
            $fresh = $trial->fresh();
            if ($fresh && ! $fresh->hasExpired()) {
                $fresh->update($attributes);
            }
        });
    }

    private function providerService(string $service): string
    {
        return match ($service) {
            'staging' => 'virtual-staging', 'decluttering' => 'revision', 'grass' => 'green-grass', 'twilight' => 'twilight'
        };
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($callback) {
            DB::table('listing_studio_trial_guard')->where('id', 1)->increment('version');

            return $callback();
        }), 'listing-studio-guest-trial');
    }
}
