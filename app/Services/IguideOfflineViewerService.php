<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Mime\MimeTypes;
use ZipArchive;

/**
 * Issues short-lived bearer links and streams a clean offline iGUIDE directly
 * from its private ZIP. Archive members are never extracted into a public path.
 */
class IguideOfflineViewerService
{
    private const SIGNATURE_VERSION = 'v1';

    private const MAX_URL_TTL_MINUTES = 60;

    /** Bound the only HTML prefix retained while inserting the storage shim. */
    private const HTML_INJECTION_PREFIX_BYTES = 256 * 1024;

    /**
     * @return array{url:string,expires_at:string,file_id:int}
     */
    public function issueViewerLink(Shoot $shoot): array
    {
        [$file, $lifecycle] = $this->resolveReadyPackage((int) $shoot->getKey());

        $ttlMinutes = max(
            1,
            min(self::MAX_URL_TTL_MINUTES, (int) config('iguide.offline_viewer.url_ttl_minutes', 60))
        );
        $expiresAt = now()->addMinutes($ttlMinutes);
        $expires = $expiresAt->timestamp;
        $signature = $this->signature((int) $shoot->getKey(), (int) $file->getKey(), $expires);
        $entryPath = $this->indexEntryPath($lifecycle);

        $routePrefix = route('api.public.iguide-offline-viewer.asset', [
            'shootId' => $shoot->getKey(),
            'fileId' => $file->getKey(),
            'expires' => $expires,
            'signature' => $signature,
        ]);
        $encodedEntryPath = implode('/', array_map('rawurlencode', explode('/', $entryPath)));

        return [
            'url' => rtrim($routePrefix, '/').'/'.$encodedEntryPath,
            'expires_at' => $expiresAt->toIso8601String(),
            'file_id' => (int) $file->getKey(),
        ];
    }

    /**
     * Issue a viewer link only when the shoot still points at a valid, clean
     * ready package. Public tour payloads use this best-effort variant so a
     * stale lifecycle pointer cannot make the rest of a property tour fail.
     *
     * @return array{url:string,expires_at:string,file_id:int}|null
     */
    public function issueViewerLinkIfReady(Shoot $shoot): ?array
    {
        try {
            return $this->issueViewerLink($shoot);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Public pages may publish only a delivered/admin-verified package whose
     * upload lifecycle explicitly attests the requested audience.
     *
     * @return array{url:string,expires_at:string,file_id:int}|null
     */
    public function issuePublicViewerLinkIfEligible(Shoot $shoot, string $audience): ?array
    {
        if (! in_array($audience, ['branded', 'mls'], true) || ! $this->isPubliclyReleased($shoot)) {
            return null;
        }

        if (! $this->hasPublicationAttestation($shoot, $audience)) {
            return null;
        }

        return $this->issueViewerLinkIfReady($shoot);
    }

    public function streamAsset(
        int $shootId,
        int $fileId,
        int $expires,
        string $signature,
        ?string $requestedPath = null
    ): StreamedResponse {
        if ($expires <= now()->timestamp || ! $this->hasValidSignature($shootId, $fileId, $expires, $signature)) {
            abort(403, 'This iGUIDE viewer link is invalid or has expired.');
        }

        [$file, $lifecycle] = $this->resolveReadyPackage($shootId, $fileId);
        $entryPath = $this->normalizeRequestedPath($requestedPath, $lifecycle);
        $archivePath = $this->resolvePrivateArchivePath($file, $shootId);

        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            abort(404, 'The iGUIDE package is not available.');
        }

        $entryIndex = $this->locateEntry($zip, $entryPath, $this->indexEntryPath($lifecycle));
        $entry = $entryIndex === false ? false : $zip->statIndex($entryIndex, ZipArchive::FL_UNCHANGED);
        if ($entryIndex === false || ! is_array($entry) || str_ends_with((string) ($entry['name'] ?? ''), '/')) {
            $zip->close();
            abort(404, 'The requested iGUIDE asset was not found.');
        }

        $stream = $zip->getStreamIndex($entryIndex, ZipArchive::FL_UNCHANGED);
        if (! is_resource($stream)) {
            $zip->close();
            abort(404, 'The requested iGUIDE asset could not be read.');
        }

        $isHtml = in_array(strtolower(pathinfo($entryPath, PATHINFO_EXTENSION)), ['html', 'htm'], true);
        $storageShim = $isHtml ? $this->storageShim() : '';
        $remainingLifetime = max(0, $expires - now()->timestamp);
        $filename = basename($entryPath);
        $fallbackFilename = preg_replace('/[^A-Za-z0-9._ -]/', '_', Str::ascii($filename));
        $fallbackFilename = is_string($fallbackFilename) && $fallbackFilename !== ''
            ? $fallbackFilename
            : 'iguide-asset';
        $headers = [
            // CSP sandboxing gives the document an opaque Origin (`null`). The
            // signed asset URLs remain bearer-protected, so allow that opaque
            // document to fetch them without exposing any unsigned ZIP path.
            'Access-Control-Allow-Methods' => 'GET, HEAD',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => "private, max-age={$remainingLifetime}, must-revalidate",
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $filename,
                $fallbackFilename
            ),
            'Content-Length' => (string) (max(0, (int) ($entry['size'] ?? 0)) + strlen($storageShim)),
            'Content-Type' => $this->contentType($entryPath),
            // Apply the sandbox to every archive response. Browsers ignore a
            // document CSP on ordinary JS/CSS/image subresource loads, while
            // an SVG, XHTML, XML, PDF, or unexpectedly active MIME opened as a
            // top-level document can never inherit the trusted API origin.
            'Content-Security-Policy' => $this->documentContentSecurityPolicy(),
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ];

        return response()->stream(function () use ($stream, $zip, $isHtml, $storageShim): void {
            try {
                if ($isHtml) {
                    $this->streamHtmlWithShim($stream, $storageShim);
                } else {
                    $this->copyStream($stream);
                }
            } finally {
                fclose($stream);
                $zip->close();
            }
        }, 200, $headers);
    }

    /**
     * @return array{0:ShootFile,1:array<string,mixed>}
     */
    private function resolveReadyPackage(int $shootId, ?int $expectedFileId = null): array
    {
        $shoot = Shoot::find($shootId);
        $lifecycle = data_get($shoot?->iguide_data, 'manual_offline_package');
        $lifecycleFileId = is_array($lifecycle) && is_numeric($lifecycle['file_id'] ?? null)
            ? (int) $lifecycle['file_id']
            : null;

        if ($shoot === null
            || ! is_array($lifecycle)
            || ($lifecycle['status'] ?? null) !== 'ready'
            || $lifecycleFileId === null
            || ($expectedFileId !== null && $lifecycleFileId !== $expectedFileId)) {
            abort(404, 'A ready iGUIDE package was not found.');
        }

        $file = ShootFile::query()
            ->whereKey($lifecycleFileId)
            ->where('shoot_id', $shootId)
            ->first();
        $lifecycleUploadId = $lifecycle['upload_id'] ?? $lifecycle['id'] ?? null;
        $fileUploadId = data_get($file?->metadata, 'upload_id');
        $lifecycleWrapper = $lifecycle['wrapper_directory'] ?? null;
        $fileWrapper = data_get($file?->metadata, 'wrapper_directory');

        if ($file === null
            || ! $file->isIguideOfflinePackage()
            || $file->scan_status !== ShootFile::SCAN_STATUS_CLEAN
            || ! is_string($lifecycleUploadId)
            || $lifecycleUploadId === ''
            || ! is_string($fileUploadId)
            || ! hash_equals($lifecycleUploadId, $fileUploadId)
            || $lifecycleWrapper !== $fileWrapper) {
            abort(404, 'A ready iGUIDE package was not found.');
        }

        $filePackageMetadata = is_array($file->metadata) ? $file->metadata : [];
        if (! hash_equals(
            $this->indexEntryPath($lifecycle),
            $this->indexEntryPath($filePackageMetadata)
        )) {
            abort(404, 'A ready iGUIDE package was not found.');
        }

        return [$file, $lifecycle];
    }

    /** @param array<string,mixed> $lifecycle */
    private function indexEntryPath(array $lifecycle): string
    {
        $wrapper = $this->validatedWrapperDirectory($lifecycle);
        $storedPath = $lifecycle['index_entry_path'] ?? null;
        if ($storedPath === null || $storedPath === '') {
            return $wrapper === null ? 'index.html' : $wrapper.'/index.html';
        }
        if (! is_string($storedPath)) {
            abort(404, 'The iGUIDE package has an invalid index entry.');
        }

        $segments = $this->validatedEntrySegments($storedPath);
        $normalized = implode('/', $segments);
        $relative = $normalized;
        if ($wrapper !== null) {
            if (! str_starts_with($normalized, $wrapper.'/')) {
                abort(404, 'The iGUIDE package has an invalid index entry.');
            }

            $relative = substr($normalized, strlen($wrapper) + 1);
        }
        if (strcasecmp($relative, 'index.html') !== 0) {
            abort(404, 'The iGUIDE package has an invalid index entry.');
        }

        return $normalized;
    }

    /** @param array<string,mixed> $lifecycle */
    private function normalizeRequestedPath(?string $requestedPath, array $lifecycle): string
    {
        if ($requestedPath === null || $requestedPath === '') {
            return $this->indexEntryPath($lifecycle);
        }

        if (strlen($requestedPath) > 2048
            || preg_match('//u', $requestedPath) !== 1
            || str_contains($requestedPath, "\0")
            || str_contains($requestedPath, '\\')
            || str_starts_with($requestedPath, '/')) {
            abort(404, 'The requested iGUIDE asset was not found.');
        }

        $path = str_ends_with($requestedPath, '/')
            ? $requestedPath.'index.html'
            : $requestedPath;
        $segments = $this->validatedEntrySegments($path);
        $normalized = implode('/', $segments);
        $wrapper = $this->validatedWrapperDirectory($lifecycle);
        if ($wrapper !== null && ! str_starts_with($normalized, $wrapper.'/')) {
            abort(404, 'The requested iGUIDE asset was not found.');
        }

        return $normalized;
    }

    /** @param array<string,mixed> $lifecycle */
    private function validatedWrapperDirectory(array $lifecycle): ?string
    {
        $wrapper = $lifecycle['wrapper_directory'] ?? null;
        if ($wrapper === null || $wrapper === '') {
            return null;
        }
        if (! is_string($wrapper)
            || strlen($wrapper) > 255
            || preg_match('//u', $wrapper) !== 1
            || str_contains($wrapper, '/')
            || str_contains($wrapper, '\\')
            || str_contains($wrapper, "\0")
            || str_contains($wrapper, ':')
            || $wrapper === '.'
            || $wrapper === '..'
            || preg_match('/[. ]$/u', $wrapper)
            || preg_match('/[\x00-\x1F\x7F]/u', $wrapper)) {
            abort(404, 'The iGUIDE package has an invalid wrapper directory.');
        }

        return $wrapper;
    }

    /** @return list<string> */
    private function validatedEntrySegments(string $path): array
    {
        if ($path === ''
            || strlen($path) > 2048
            || preg_match('//u', $path) !== 1
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')) {
            abort(404, 'The requested iGUIDE asset was not found.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || str_contains($segment, ':')
                || preg_match('/[. ]$/u', $segment)
                || preg_match('/[\x00-\x1F\x7F]/u', $segment)) {
                abort(404, 'The requested iGUIDE asset was not found.');
            }
        }

        return $segments;
    }

    private function locateEntry(ZipArchive $zip, string $entryPath, string $indexEntryPath): int|false
    {
        $exact = $zip->locateName($entryPath, ZipArchive::FL_UNCHANGED);
        if ($exact !== false || strcasecmp($entryPath, $indexEntryPath) !== 0) {
            return $exact;
        }

        // Legacy lifecycle rows predate index_entry_path and therefore assume
        // lowercase index.html. Inspection rejects case-colliding paths, but
        // recount here so an on-disk archive changed after scanning still fails
        // closed instead of choosing one ambiguous member.
        $match = false;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            if (! is_string($name) || str_ends_with($name, '/') || strcasecmp($name, $entryPath) !== 0) {
                continue;
            }
            if ($match !== false) {
                return false;
            }

            $match = $index;
        }

        return $match;
    }

    /** @param resource $stream */
    private function copyStream($stream): void
    {
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
        }
    }

    /** @param resource $stream */
    private function streamHtmlWithShim($stream, string $shim): void
    {
        $prefix = '';
        $injectionOffset = null;

        while (! feof($stream) && strlen($prefix) < self::HTML_INJECTION_PREFIX_BYTES) {
            $readLength = min(64 * 1024, self::HTML_INJECTION_PREFIX_BYTES - strlen($prefix));
            $chunk = fread($stream, $readLength);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $prefix .= $chunk;
            $injectionOffset = $this->preferredHtmlInjectionOffset($prefix);
            if ($injectionOffset !== null) {
                break;
            }
        }

        $injectionOffset ??= $this->fallbackHtmlInjectionOffset($prefix);
        echo substr($prefix, 0, $injectionOffset), $shim, substr($prefix, $injectionOffset);
        $this->copyStream($stream);
    }

    private function preferredHtmlInjectionOffset(string $prefix): ?int
    {
        $headEnd = null;
        if (preg_match('/<head\b[^>]*>/i', $prefix, $head, PREG_OFFSET_CAPTURE) === 1) {
            $headEnd = $head[0][1] + strlen($head[0][0]);
        }

        $scriptStart = null;
        if (preg_match('/<script\b/i', $prefix, $script, PREG_OFFSET_CAPTURE) === 1) {
            $scriptStart = $script[0][1];
        }

        if ($scriptStart !== null && ($headEnd === null || $scriptStart < $headEnd)) {
            return $scriptStart;
        }

        return $headEnd ?? $scriptStart;
    }

    private function fallbackHtmlInjectionOffset(string $prefix): int
    {
        $preferred = $this->preferredHtmlInjectionOffset($prefix);
        if ($preferred !== null) {
            return $preferred;
        }
        if (preg_match('/<!doctype\b[^>]*>/i', $prefix, $doctype, PREG_OFFSET_CAPTURE) === 1) {
            return $doctype[0][1] + strlen($doctype[0][0]);
        }

        return 0;
    }

    private function storageShim(): string
    {
        return <<<'HTML'
<script data-repro-iguide-storage-shim>(function(){"use strict";
var BYTE_LIMIT=1048576,ITEM_LIMIT=1024;
function quotaError(){try{return new DOMException("The quota has been exceeded.","QuotaExceededError");}catch(error){var fallback=new Error("The quota has been exceeded.");fallback.name="QuotaExceededError";return fallback;}}
function createStore(){var values=Object.create(null),keys=[],bytes=0,hasOwn=Object.prototype.hasOwnProperty;
function text(value){return String(value);}function entryBytes(key,value){return 2*(key.length+value.length);}
var api=Object.create(null);Object.defineProperties(api,{
getItem:{configurable:true,value:function(key){key=text(key);return hasOwn.call(values,key)?values[key]:null;}},
setItem:{configurable:true,value:function(key,value){key=text(key);value=text(value);var exists=hasOwn.call(values,key),next=bytes-(exists?entryBytes(key,values[key]):0)+entryBytes(key,value);if((!exists&&keys.length>=ITEM_LIMIT)||next>BYTE_LIMIT)throw quotaError();if(!exists)keys.push(key);values[key]=value;bytes=next;}},
removeItem:{configurable:true,value:function(key){key=text(key);if(!hasOwn.call(values,key))return;bytes-=entryBytes(key,values[key]);delete values[key];keys.splice(keys.indexOf(key),1);}},
clear:{configurable:true,value:function(){values=Object.create(null);keys=[];bytes=0;}},
key:{configurable:true,value:function(index){index=Number(index);return Number.isInteger(index)&&index>=0&&index<keys.length?keys[index]:null;}},
length:{configurable:true,get:function(){return keys.length;}}
});
if(typeof Proxy!=="function")return api;return new Proxy(api,{
get:function(target,property){if(typeof property!=="string"||property in target)return target[property];return target.getItem(property);},
set:function(target,property,value){if(typeof property!=="string"||property in target)return false;target.setItem(property,value);return true;},
deleteProperty:function(target,property){if(typeof property!=="string"||property in target)return false;target.removeItem(property);return true;},
has:function(target,property){return property in target||(typeof property==="string"&&hasOwn.call(values,property));},
ownKeys:function(target){return Object.getOwnPropertyNames(target).concat(keys.filter(function(key){return !(key in target);}));},
getOwnPropertyDescriptor:function(target,property){if(property in target)return Object.getOwnPropertyDescriptor(target,property);if(typeof property==="string"&&hasOwn.call(values,property))return{configurable:true,enumerable:true,writable:true,value:values[property]};}
});}
function install(name){var storage=createStore();try{Object.defineProperty(window,name,{configurable:true,enumerable:true,value:storage});return;}catch(error){}try{Object.defineProperty(Window.prototype,name,{configurable:true,get:function(){return storage;}});}catch(error){}}
install("localStorage");install("sessionStorage");
})();</script>
HTML;
    }

    private function documentContentSecurityPolicy(): string
    {
        $origin = $this->resourceOrigin();
        $frameAncestors = array_values(array_unique(array_filter(array_map(
            fn (mixed $candidate): ?string => $this->normalizeHttpOrigin($candidate),
            [
                ...((array) config('cors.allowed_origins', [])),
                config('app.frontend_url'),
            ]
        ))));
        $ancestorSources = implode(' ', ["'self'", ...$frameAncestors]);

        return implode('; ', [
            'sandbox allow-scripts allow-pointer-lock allow-modals',
            "default-src 'none'",
            "base-uri 'none'",
            "object-src 'none'",
            "form-action 'none'",
            "frame-src 'none'",
            "frame-ancestors {$ancestorSources}",
            "script-src {$origin} 'unsafe-inline' 'unsafe-eval' blob:",
            "style-src {$origin} 'unsafe-inline'",
            "img-src {$origin} data: blob:",
            "font-src {$origin} data:",
            "media-src {$origin} data: blob:",
            "connect-src {$origin}",
            "manifest-src {$origin}",
            "worker-src {$origin} blob:",
            "navigate-to {$origin}",
        ]).';';
    }

    private function resourceOrigin(): string
    {
        $candidates = [
            app()->bound('request') ? request()->getSchemeAndHttpHost() : null,
            config('app.url'),
        ];
        foreach ($candidates as $candidate) {
            $origin = $this->normalizeHttpOrigin($candidate);
            if ($origin !== null) {
                return $origin;
            }
        }

        throw new \RuntimeException('A valid API origin is required for the iGUIDE viewer CSP.');
    }

    private function normalizeHttpOrigin(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        $parts = parse_url($candidate);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || preg_match('/^[A-Za-z0-9._:\[\]-]+$/D', $host) !== 1
            || ($port !== null && (! is_int($port) || $port < 1 || $port > 65535))) {
            return null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    private function resolvePrivateArchivePath(ShootFile $file, int $shootId): string
    {
        $expectedPrefix = "secure/iguide-packages/{$shootId}/";
        foreach ([$file->path, $file->storage_path] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', ltrim($candidate, '/\\'));
            if (! str_starts_with($normalized, $expectedPrefix)
                || str_contains($normalized, '../')
                || ! Storage::disk('local')->exists($normalized)) {
                continue;
            }

            $archivePath = realpath(Storage::disk('local')->path($normalized));
            $privateRoot = realpath(Storage::disk('local')->path(''));
            if ($archivePath !== false
                && $privateRoot !== false
                && is_file($archivePath)
                && str_starts_with(
                    str_replace('\\', '/', $archivePath),
                    rtrim(str_replace('\\', '/', $privateRoot), '/').'/'
                )) {
                return $archivePath;
            }
        }

        abort(404, 'The iGUIDE package is not available.');
    }

    private function isPubliclyReleased(Shoot $shoot): bool
    {
        if ($shoot->admin_verified_at !== null) {
            return true;
        }

        $releasedStatuses = [
            Shoot::STATUS_DELIVERED,
            'ready_for_client',
            'admin_verified',
            'workflow_completed',
            'client_delivered',
            'finalized',
            'finalised',
        ];

        return in_array(strtolower((string) $shoot->status), $releasedStatuses, true)
            || in_array(strtolower((string) $shoot->workflow_status), $releasedStatuses, true);
    }

    private function hasPublicationAttestation(Shoot $shoot, string $audience): bool
    {
        $lifecycle = data_get($shoot->iguide_data, 'manual_offline_package');
        if (! is_array($lifecycle) || ($lifecycle['status'] ?? null) !== 'ready') {
            return false;
        }

        $attestation = $lifecycle['publication_attestation'] ?? null;
        if (! is_array($attestation)
            || ($attestation['policy'] ?? null) !== 'authorized_staff_official_iguide_export'
            || (int) ($attestation['version'] ?? 0) !== 1
            || ! in_array($audience, (array) ($attestation['audiences'] ?? []), true)
            || ! is_numeric($attestation['attested_by'] ?? null)
            || ! filled($attestation['attested_at'] ?? null)) {
            return false;
        }

        $actor = User::withTrashed()->find((int) $attestation['attested_by']);
        $role = strtolower(str_replace(['_', '-', ' '], '', (string) ($actor?->role ?? '')));
        if (! in_array($role, ['admin', 'superadmin', 'editingmanager'], true)) {
            return false;
        }

        return true;
    }

    private function hasValidSignature(int $shootId, int $fileId, int $expires, string $provided): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $provided)) {
            return false;
        }

        foreach ($this->signingKeys() as $key) {
            if (hash_equals($this->signature($shootId, $fileId, $expires, $key), $provided)) {
                return true;
            }
        }

        return false;
    }

    private function signature(int $shootId, int $fileId, int $expires, ?string $key = null): string
    {
        $key ??= $this->signingKeys()[0] ?? throw new \RuntimeException('APP_KEY is required for iGUIDE viewer links.');
        $payload = implode('|', [self::SIGNATURE_VERSION, $shootId, $fileId, $expires]);

        return hash_hmac('sha256', $payload, $key);
    }

    /** @return list<string> */
    private function signingKeys(): array
    {
        $keys = array_filter([
            Config::get('app.key'),
            ...((array) Config::get('app.previous_keys', [])),
        ], static fn (mixed $key): bool => is_string($key) && $key !== '');

        return array_values(array_unique(array_filter(array_map(
            static function (string $key): string {
                if (Str::startsWith($key, 'base64:')) {
                    $decoded = base64_decode(Str::after($key, 'base64:'), true);

                    return $decoded === false ? '' : $decoded;
                }

                return $key;
            },
            $keys
        ), static fn (string $key): bool => $key !== '')));
    }

    private function contentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = match ($extension) {
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js', 'mjs' => 'text/javascript',
            'json', 'map' => 'application/json',
            'webmanifest' => 'application/manifest+json',
            'xhtml', 'xht' => 'application/xhtml+xml',
            'xml' => 'application/xml',
            'xsl', 'xslt' => 'application/xslt+xml',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'wasm' => 'application/wasm',
            'pcd' => 'application/octet-stream',
            default => MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'application/octet-stream',
        };

        return str_starts_with($type, 'text/') || in_array($type, [
            'application/javascript',
            'application/json',
            'application/manifest+json',
            'application/xhtml+xml',
            'application/xml',
            'application/xslt+xml',
            'image/svg+xml',
        ], true)
            ? $type.'; charset=UTF-8'
            : $type;
    }
}
