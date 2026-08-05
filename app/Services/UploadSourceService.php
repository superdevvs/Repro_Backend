<?php

namespace App\Services;

use App\Models\OauthToken;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class UploadSourceService
{
    public const PROVIDERS = ['dropbox', 'google_drive', 'google_photos', 'onedrive'];

    /**
     * Extensions accepted from cloud imports IN ADDITION to the direct-upload
     * allow-list in config/uploads.php. Cloud sources routinely hold extended
     * RAW formats, extra video containers and PDFs we still ingest, so the
     * import allow-list is an explicit, documented superset of the shared
     * config list rather than a silently divergent copy (task 13.3). The
     * effective list is assembled in allowedExtensions().
     *
     * @var list<string>
     */
    private const IMPORT_ONLY_EXTENSIONS = [
        'webp', 'pdf', 'mkv', 'wmv', 'webm', 'mpg', 'mpeg',
        'nrw', 'srf', 'sr2', 'dng', 'raf', 'orf', 'pef', 'rw2', 'srw',
        '3fr', 'fff', 'iiq', 'rwl', 'x3f',
    ];

    /**
     * Narrow, explicit content types accepted for a remote file that arrives
     * without a usable extension. Replaces the previous "any image/* or video/*"
     * prefix match, which a spoofed Content-Type header could abuse (task 13.3).
     *
     * @var list<string>
     */
    private const SAFE_IMPORT_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff',
        'image/bmp', 'image/heic', 'image/heif',
        'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm',
        'application/pdf',
    ];

    private const MAX_REMOTE_BYTES = 2000 * 1024 * 1024;

    public function statuses(User $user): array
    {
        return collect(self::PROVIDERS)
            ->mapWithKeys(fn (string $provider) => [$provider => $this->providerStatus($provider, $user)])
            ->all();
    }

    public function providerStatus(string $provider, User $user): array
    {
        $this->assertProvider($provider);

        $personal = $this->findToken($provider, $user, false);
        $shared = $this->findToken($provider, $user, true);
        $configured = $this->isConfigured($provider);
        $envAvailable = $provider === 'dropbox' && (bool) config('services.dropbox.access_token');
        $token = $personal ?: $shared;

        return [
            'provider' => $provider,
            'label' => $this->label($provider),
            'configured' => $configured || $envAvailable,
            'connected' => (bool) ($token || $envAvailable),
            'account_type' => $personal ? 'personal' : ($shared || $envAvailable ? 'shared' : null),
            'account_email' => $token?->provider_account_email,
            'account_name' => $token?->provider_account_name,
            'expired' => $token?->expires_at ? $token->expires_at->isPast() : false,
            'supports_oauth' => $configured,
            'message' => ($configured || $envAvailable)
                ? null
                : "{$this->label($provider)} OAuth credentials are not configured.",
        ];
    }

    public function buildAuthorizationUrl(string $provider, User $user, string $accountType = 'personal'): string
    {
        $this->assertProvider($provider);

        if (!$this->isConfigured($provider)) {
            throw new RuntimeException("{$this->label($provider)} OAuth credentials are not configured.");
        }

        $state = Crypt::encryptString(json_encode([
            'provider' => $provider,
            'user_id' => $user->id,
            'account_type' => $accountType === 'shared' ? 'shared' : 'personal',
            'nonce' => Str::random(24),
        ]));

        return match ($provider) {
            'dropbox' => 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
                'client_id' => config('services.dropbox.client_id'),
                'redirect_uri' => config('services.dropbox.redirect'),
                'response_type' => 'code',
                'token_access_type' => 'offline',
                'state' => $state,
            ]),
            'google_drive' => $this->googleAuthUrl('google_drive', $state, 'https://www.googleapis.com/auth/drive.readonly'),
            'google_photos' => $this->googleAuthUrl('google_photos', $state, 'https://www.googleapis.com/auth/photoslibrary.readonly'),
            'onedrive' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query([
                'client_id' => config('services.microsoft.client_id'),
                'redirect_uri' => config('services.microsoft.redirect'),
                'response_type' => 'code',
                'response_mode' => 'query',
                'scope' => 'offline_access Files.Read User.Read',
                'state' => $state,
            ]),
        };
    }

    public function completeAuthorization(string $provider, string $code, string $state): OauthToken
    {
        $this->assertProvider($provider);
        $payload = json_decode(Crypt::decryptString($state), true);
        if (!is_array($payload) || ($payload['provider'] ?? null) !== $provider) {
            throw new RuntimeException('Invalid OAuth state.');
        }

        $accountType = ($payload['account_type'] ?? 'personal') === 'shared' ? 'shared' : 'personal';
        $userId = $accountType === 'shared' ? null : (int) ($payload['user_id'] ?? 0);
        $tokenData = $this->exchangeCode($provider, $code);
        $account = $this->fetchAccount($provider, $tokenData['access_token']);

        return OauthToken::updateOrCreate(
            [
                'provider' => $provider,
                'user_id' => $userId ?: null,
                'account_type' => $accountType,
            ],
            [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds(max(((int) $tokenData['expires_in']) - 60, 0))
                    : null,
                'scopes' => $tokenData['scope'] ?? null,
                'provider_account_id' => $account['id'] ?? null,
                'provider_account_email' => $account['email'] ?? null,
                'provider_account_name' => $account['name'] ?? null,
                'metadata' => ['connected_at' => now()->toIso8601String()],
            ],
        );
    }

    public function disconnect(string $provider, User $user): void
    {
        $this->assertProvider($provider);
        OauthToken::query()
            ->where('provider', $provider)
            ->where('user_id', $user->id)
            ->where('account_type', 'personal')
            ->delete();
    }

    public function listItems(string $provider, User $user, array $params = []): array
    {
        $this->assertProvider($provider);
        $params = $params ?: [];
        $token = $this->resolveAccessToken($provider, $user);

        return match ($provider) {
            'dropbox' => $this->listDropboxItems($token, (string) ($params['path'] ?? '')),
            'google_drive' => $this->listGoogleDriveItems($token, (string) ($params['folder_id'] ?? 'root')),
            'google_photos' => $this->listGooglePhotosItems($token, $params),
            'onedrive' => $this->listOneDriveItems($token, (string) ($params['folder_id'] ?? 'root')),
        };
    }

    public function makeUploadedFileFromUrl(string $url): UploadedFile
    {
        $this->assertSafeRemoteUrl($url);

        $tmpPath = tempnam(sys_get_temp_dir(), 'upload-source-url-');
        if (!$tmpPath) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        $response = Http::timeout(120)
            ->withOptions(['sink' => $tmpPath, 'verify' => app()->environment('production')])
            ->get($url);

        if ($response->failed()) {
            @unlink($tmpPath);
            throw new RuntimeException('The URL could not be downloaded.');
        }

        $size = filesize($tmpPath) ?: 0;
        if ($size <= 0 || $size > self::MAX_REMOTE_BYTES) {
            @unlink($tmpPath);
            throw new RuntimeException('The remote file is empty or exceeds the 2GB limit.');
        }

        $contentType = (string) $response->header('Content-Type', 'application/octet-stream');
        $name = $this->filenameFromUrl($url, $contentType);
        $this->assertSupportedFilename($name, $contentType);

        return new UploadedFile($tmpPath, $name, $contentType, null, true);
    }

    public function makeUploadedFileFromProviderItem(string $provider, User $user, array $item): UploadedFile
    {
        $this->assertProvider($provider);
        $token = $this->resolveAccessToken($provider, $user);
        $tmpPath = tempnam(sys_get_temp_dir(), 'upload-source-provider-');
        if (!$tmpPath) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        [$name, $contentType] = match ($provider) {
            'dropbox' => $this->downloadDropboxItem($token, $item, $tmpPath),
            'google_drive' => $this->downloadGoogleDriveItem($token, $item, $tmpPath),
            'google_photos' => $this->downloadGooglePhotosItem($token, $item, $tmpPath),
            'onedrive' => $this->downloadOneDriveItem($token, $item, $tmpPath),
        };

        $size = filesize($tmpPath) ?: 0;
        if ($size <= 0 || $size > self::MAX_REMOTE_BYTES) {
            @unlink($tmpPath);
            throw new RuntimeException('The source file is empty or exceeds the 2GB limit.');
        }

        $this->assertSupportedFilename($name, $contentType);

        return new UploadedFile($tmpPath, $name, $contentType ?: 'application/octet-stream', null, true);
    }

    private function googleAuthUrl(string $provider, string $state, string $scope): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config("services.google.upload_sources_{$provider}_redirect", config('app.url') . "/api/upload-sources/{$provider}/callback"),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'include_granted_scopes' => 'true',
            'scope' => "openid email {$scope}",
            'state' => $state,
        ]);
    }

    private function exchangeCode(string $provider, string $code): array
    {
        $response = match ($provider) {
            'dropbox' => Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => config('services.dropbox.client_id'),
                'client_secret' => config('services.dropbox.client_secret'),
                'redirect_uri' => config('services.dropbox.redirect'),
            ]),
            'google_drive', 'google_photos' => Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config("services.google.upload_sources_{$provider}_redirect", config('app.url') . "/api/upload-sources/{$provider}/callback"),
                'grant_type' => 'authorization_code',
            ]),
            'onedrive' => Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'code' => $code,
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'redirect_uri' => config('services.microsoft.redirect'),
                'grant_type' => 'authorization_code',
            ]),
        };

        if ($response->failed() || !$response->json('access_token')) {
            throw new RuntimeException("Unable to connect {$this->label($provider)}.");
        }

        return $response->json();
    }

    private function resolveAccessToken(string $provider, User $user): string
    {
        if ($provider === 'dropbox' && config('services.dropbox.access_token')) {
            $personal = $this->findToken($provider, $user, false);
            if (!$personal) {
                return (string) config('services.dropbox.access_token');
            }
        }

        $token = $this->findToken($provider, $user, false) ?: $this->findToken($provider, $user, true);
        if (!$token) {
            throw new RuntimeException("Connect {$this->label($provider)} before browsing files.");
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return $this->refreshToken($token);
        }

        return (string) $token->access_token;
    }

    private function findToken(string $provider, User $user, bool $shared): ?OauthToken
    {
        return OauthToken::query()
            ->where('provider', $provider)
            ->when($shared, fn ($query) => $query->where('account_type', 'shared')->whereNull('user_id'))
            ->when(!$shared, fn ($query) => $query->where('account_type', 'personal')->where('user_id', $user->id))
            ->latest()
            ->first();
    }

    private function refreshToken(OauthToken $token): string
    {
        if (!$token->refresh_token) {
            throw new RuntimeException("Reconnect {$this->label($token->provider)}.");
        }

        $response = match ($token->provider) {
            'dropbox' => Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
                'client_id' => config('services.dropbox.client_id'),
                'client_secret' => config('services.dropbox.client_secret'),
            ]),
            'google_drive', 'google_photos' => Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
            ]),
            'onedrive' => Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
            ]),
            default => throw new RuntimeException('Unsupported provider.'),
        };

        if ($response->failed() || !$response->json('access_token')) {
            throw new RuntimeException("Reconnect {$this->label($token->provider)}.");
        }

        $token->forceFill([
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?: $token->refresh_token,
            'expires_at' => now()->addSeconds(max(((int) $response->json('expires_in', 3600)) - 60, 0)),
            'scopes' => $response->json('scope') ?: $token->scopes,
        ])->save();

        return (string) $token->access_token;
    }

    private function listDropboxItems(string $token, string $path): array
    {
        $response = Http::withToken($token)
            ->withOptions(['verify' => app()->environment('production')])
            ->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => $path === '/' ? '' : $path,
                'recursive' => false,
                'include_media_info' => true,
                'include_deleted' => false,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Could not list Dropbox files.');
        }

        return [
            'items' => collect($response->json('entries', []))->map(fn ($entry) => [
                'id' => $entry['id'] ?? ($entry['path_display'] ?? ''),
                'name' => $entry['name'] ?? '',
                'path' => $entry['path_display'] ?? '',
                'is_folder' => ($entry['.tag'] ?? null) === 'folder',
                'size' => $entry['size'] ?? null,
                'mime_type' => $this->mimeFromName($entry['name'] ?? ''),
                'modified' => $entry['server_modified'] ?? null,
            ])->values(),
            'path' => $path,
            'has_more' => (bool) $response->json('has_more', false),
        ];
    }

    private function listGoogleDriveItems(string $token, string $folderId): array
    {
        $q = $folderId === 'root'
            ? "'root' in parents and trashed = false"
            : "'" . str_replace("'", "\\'", $folderId) . "' in parents and trashed = false";

        $response = Http::withToken($token)->get('https://www.googleapis.com/drive/v3/files', [
            'q' => $q,
            'fields' => 'files(id,name,mimeType,size,modifiedTime,thumbnailLink)',
            'pageSize' => 100,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Could not list Google Drive files.');
        }

        return [
            'items' => collect($response->json('files', []))->map(fn ($entry) => [
                'id' => $entry['id'] ?? '',
                'name' => $entry['name'] ?? '',
                'is_folder' => ($entry['mimeType'] ?? '') === 'application/vnd.google-apps.folder',
                'mime_type' => $entry['mimeType'] ?? null,
                'size' => isset($entry['size']) ? (int) $entry['size'] : null,
                'modified' => $entry['modifiedTime'] ?? null,
                'thumbnail_url' => $entry['thumbnailLink'] ?? null,
            ])->values(),
            'folder_id' => $folderId,
        ];
    }

    private function listGooglePhotosItems(string $token, array $params): array
    {
        if (!empty($params['album_id'])) {
            $response = Http::withToken($token)->post('https://photoslibrary.googleapis.com/v1/mediaItems:search', [
                'albumId' => $params['album_id'],
                'pageSize' => 100,
            ]);

            if ($response->failed()) {
                throw new RuntimeException('Could not list Google Photos media.');
            }

            return [
                'items' => collect($response->json('mediaItems', []))->map(fn ($entry) => [
                    'id' => $entry['id'] ?? '',
                    'name' => $entry['filename'] ?? 'Google Photos item',
                    'is_folder' => false,
                    'mime_type' => $entry['mimeType'] ?? null,
                    'size' => null,
                    'modified' => $entry['mediaMetadata']['creationTime'] ?? null,
                    'thumbnail_url' => isset($entry['baseUrl']) ? $entry['baseUrl'] . '=w240-h180' : null,
                ])->values(),
                'album_id' => $params['album_id'],
            ];
        }

        $response = Http::withToken($token)->get('https://photoslibrary.googleapis.com/v1/albums', [
            'pageSize' => 50,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Could not list Google Photos albums.');
        }

        return [
            'items' => collect($response->json('albums', []))->map(fn ($entry) => [
                'id' => $entry['id'] ?? '',
                'name' => $entry['title'] ?? 'Album',
                'is_folder' => true,
                'item_count' => isset($entry['mediaItemsCount']) ? (int) $entry['mediaItemsCount'] : null,
                'thumbnail_url' => isset($entry['coverPhotoBaseUrl']) ? $entry['coverPhotoBaseUrl'] . '=w240-h180' : null,
            ])->values(),
        ];
    }

    private function listOneDriveItems(string $token, string $folderId): array
    {
        $url = $folderId === 'root'
            ? 'https://graph.microsoft.com/v1.0/me/drive/root/children'
            : "https://graph.microsoft.com/v1.0/me/drive/items/{$folderId}/children";

        $response = Http::withToken($token)->get($url, [
            '$top' => 100,
            '$select' => 'id,name,size,file,folder,lastModifiedDateTime,thumbnails',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Could not list OneDrive files.');
        }

        return [
            'items' => collect($response->json('value', []))->map(fn ($entry) => [
                'id' => $entry['id'] ?? '',
                'name' => $entry['name'] ?? '',
                'is_folder' => isset($entry['folder']),
                'mime_type' => $entry['file']['mimeType'] ?? $this->mimeFromName($entry['name'] ?? ''),
                'size' => $entry['size'] ?? null,
                'modified' => $entry['lastModifiedDateTime'] ?? null,
                'thumbnail_url' => Arr::get($entry, 'thumbnails.0.medium.url'),
            ])->values(),
            'folder_id' => $folderId,
        ];
    }

    private function downloadDropboxItem(string $token, array $item, string $tmpPath): array
    {
        $path = (string) ($item['path'] ?? $item['id'] ?? '');
        $response = Http::withToken($token)
            ->withOptions(['sink' => $tmpPath, 'verify' => app()->environment('production')])
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $path])])
            ->post('https://content.dropboxapi.com/2/files/download');

        if ($response->failed()) {
            throw new RuntimeException('Could not download Dropbox file.');
        }

        return [$item['name'] ?? basename($path), $response->header('Content-Type') ?: $this->mimeFromName((string) ($item['name'] ?? $path))];
    }

    private function downloadGoogleDriveItem(string $token, array $item, string $tmpPath): array
    {
        $id = (string) ($item['id'] ?? '');
        $response = Http::withToken($token)
            ->withOptions(['sink' => $tmpPath])
            ->get("https://www.googleapis.com/drive/v3/files/{$id}", ['alt' => 'media']);

        if ($response->failed()) {
            throw new RuntimeException('Could not download Google Drive file.');
        }

        return [$item['name'] ?? "drive-{$id}", $response->header('Content-Type') ?: ($item['mime_type'] ?? null)];
    }

    private function downloadGooglePhotosItem(string $token, array $item, string $tmpPath): array
    {
        $id = (string) ($item['id'] ?? '');
        $metadata = Http::withToken($token)->get("https://photoslibrary.googleapis.com/v1/mediaItems/{$id}");
        if ($metadata->failed() || !$metadata->json('baseUrl')) {
            throw new RuntimeException('Could not resolve Google Photos media.');
        }

        $response = Http::withOptions(['sink' => $tmpPath])->get($metadata->json('baseUrl') . '=d');
        if ($response->failed()) {
            throw new RuntimeException('Could not download Google Photos media.');
        }

        return [$metadata->json('filename') ?: ($item['name'] ?? "photo-{$id}.jpg"), $metadata->json('mimeType') ?: $response->header('Content-Type')];
    }

    private function downloadOneDriveItem(string $token, array $item, string $tmpPath): array
    {
        $id = (string) ($item['id'] ?? '');
        $response = Http::withToken($token)
            ->withOptions(['sink' => $tmpPath])
            ->get("https://graph.microsoft.com/v1.0/me/drive/items/{$id}/content");

        if ($response->failed()) {
            throw new RuntimeException('Could not download OneDrive file.');
        }

        return [$item['name'] ?? "onedrive-{$id}", $response->header('Content-Type') ?: ($item['mime_type'] ?? null)];
    }

    private function fetchAccount(string $provider, string $accessToken): array
    {
        try {
            $response = match ($provider) {
                'dropbox' => Http::withToken($accessToken)->withBody('null')->post('https://api.dropboxapi.com/2/users/get_current_account'),
                'google_drive', 'google_photos' => Http::withToken($accessToken)->get('https://openidconnect.googleapis.com/v1/userinfo'),
                'onedrive' => Http::withToken($accessToken)->get('https://graph.microsoft.com/v1.0/me?$select=id,displayName,userPrincipalName,mail'),
            };

            if ($response->failed()) {
                return [];
            }

            $data = $response->json() ?: [];
            return match ($provider) {
                'dropbox' => [
                    'id' => $data['account_id'] ?? null,
                    'email' => $data['email'] ?? null,
                    'name' => $data['name']['display_name'] ?? null,
                ],
                'google_drive', 'google_photos' => [
                    'id' => $data['sub'] ?? null,
                    'email' => $data['email'] ?? null,
                    'name' => $data['name'] ?? null,
                ],
                'onedrive' => [
                    'id' => $data['id'] ?? null,
                    'email' => $data['mail'] ?? $data['userPrincipalName'] ?? null,
                    'name' => $data['displayName'] ?? null,
                ],
            };
        } catch (\Throwable $e) {
            Log::warning('Upload source account lookup failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private function assertSafeRemoteUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Only http and https URLs are supported.');
        }

        $host = (string) $parts['host'];
        if (in_array(strtolower($host), ['localhost'], true)) {
            throw new RuntimeException('Local URLs cannot be imported.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (empty($ips)) {
            throw new RuntimeException('The URL host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Private or reserved network URLs cannot be imported.');
            }
        }
    }

    private function filenameFromUrl(string $url, string $contentType): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $name = basename($path);
        if ($name && str_contains($name, '.')) {
            return urldecode($name);
        }

        $extension = match (strtolower(strtok($contentType, ';') ?: '')) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        return 'remote-upload-' . now()->format('YmdHis') . ".{$extension}";
    }

    /**
     * The effective cloud-import allow-list: the shared direct-upload extensions
     * from config/uploads.php (minus archives, which are only accepted via the
     * staff-gated direct upload path since this service performs no role check)
     * plus the import-only superset above.
     *
     * @return list<string>
     */
    private function allowedExtensions(): array
    {
        $configured = array_map('strtolower', (array) config('uploads.allowed_types', []));
        // Archives (e.g. .zip) are never accepted via cloud import: their
        // contents are unscanned and the staff-role gate lives only on the
        // direct upload path (Req 5.9).
        $configured = array_diff($configured, ['zip']);

        return array_values(array_unique(array_merge($configured, self::IMPORT_ONLY_EXTENSIONS)));
    }

    private function assertSupportedFilename(string $name, ?string $contentType): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Primary check: the file's extension must be in the allow-list. Unlike
        // the previous logic, a non-allow-listed extension is NOT rescued by an
        // image/* or video/* Content-Type, so a renamed binary served with a
        // spoofed media type is rejected (task 13.3).
        if ($extension !== '') {
            if (!in_array($extension, $this->allowedExtensions(), true)) {
                throw new RuntimeException('This source file type is not supported for upload.');
            }

            return;
        }

        // Extension-less remote files fall back to a narrow, explicit safe-MIME
        // allow-list rather than a broad prefix match.
        $mime = strtolower(strtok((string) $contentType, ';') ?: '');
        if (!in_array($mime, self::SAFE_IMPORT_MIME_TYPES, true)) {
            throw new RuntimeException('This source file type is not supported for upload.');
        }
    }

    private function mimeFromName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }

    private function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'dropbox' => (bool) (config('services.dropbox.client_id') && config('services.dropbox.client_secret')),
            'google_drive', 'google_photos' => (bool) (config('services.google.client_id') && config('services.google.client_secret')),
            'onedrive' => (bool) (config('services.microsoft.client_id') && config('services.microsoft.client_secret')),
        };
    }

    private function label(string $provider): string
    {
        return match ($provider) {
            'dropbox' => 'Dropbox',
            'google_drive' => 'Google Drive',
            'google_photos' => 'Google Photos',
            'onedrive' => 'OneDrive',
            default => $provider,
        };
    }

    private function assertProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('Unsupported upload source.');
        }
    }
}
