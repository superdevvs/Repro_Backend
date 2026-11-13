<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/** Sign the final host, without changing the URL generator used by the app. */
class MediaDownloadUrl
{
    public function enabled(): bool
    {
        return trim((string) config('media.download_origin', '')) !== '';
    }

    public function signed(string $key, int $ttl, ?string $filename = null, bool $download = true): string
    {
        $generator = clone URL::getFacadeRoot();
        if ($download && $this->enabled()) {
            $origin = rtrim(trim((string) config('media.download_origin')), '/');
            $parts = parse_url($origin);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
                || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
                || ! filter_var($origin, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('MEDIA_DOWNLOAD_ORIGIN must be an HTTPS origin without a path, port or credentials.');
            }
            $generator->forceRootUrl($origin);
            $generator->forceScheme('https');
        }

        $parameters = ['path' => $key];
        if ($filename !== null) {
            $parameters['download'] = basename(str_replace('\\', '/', $filename));
        }

        return $generator->temporarySignedRoute('api.public.shoot-media.file', now()->addSeconds(max(1, $ttl)), $parameters);
    }
}
