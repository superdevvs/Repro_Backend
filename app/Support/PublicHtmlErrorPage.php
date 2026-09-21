<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicHtmlErrorPage
{
    /**
     * Browser navigations to missing or expired public links should see the
     * blueprint page. JSON clients and iGUIDE asset subrequests stay machine-readable.
     */
    public static function shouldRender(Request $request, int $status): bool
    {
        if (! in_array($status, [403, 404, 410], true)) {
            return false;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if ($request->expectsJson() || $request->ajax()) {
            return false;
        }

        $accept = strtolower((string) $request->header('Accept', ''));
        if ($accept === '' || ! str_contains($accept, 'text/html')) {
            return false;
        }

        if (self::isViewerAssetPath($request->path())) {
            return false;
        }

        return true;
    }

    /**
     * Relative files inside an offline iGUIDE must keep a machine 404.
     * The tour document itself (index.html or the bare short code) is HTML.
     */
    private static function isViewerAssetPath(string $path): bool
    {
        $path = strtolower($path);
        if (preg_match('#^api/g/[a-z0-9]+/.+\.([a-z0-9]{1,8})$#', $path, $match) !== 1
            && preg_match('#^api/iguide/offline-view/.+\.([a-z0-9]{1,8})$#', $path, $match) !== 1) {
            return false;
        }

        return ! in_array($match[1], ['html', 'htm'], true);
    }

    public static function response(Request $request, int $status): Response
    {
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $response = response()
            ->view('errors.public', [
                'homeUrl' => $frontend.'/',
                'logoOnDark' => $frontend.'/REPRO-HQ.png',
                'logoOnLight' => $frontend.'/'.rawurlencode('Repro HQ dark.png'),
                'pageTitle' => 'Page not found · R/E Pro Photos',
                'brandName' => 'R/E Pro Photos',
            ], $status)
            ->header('Cache-Control', 'no-store')
            ->header('Content-Type', 'text/html; charset=UTF-8');

        return self::withApiCors($request, $response);
    }

    private static function withApiCors(Request $request, Response $response): Response
    {
        if (! $request->is('api/*')) {
            return $response;
        }

        $allowedOrigins = config('cors.allowed_origins', []);
        $originHeader = $request->headers->get('Origin');
        $origin = '*';

        if ($originHeader && in_array($originHeader, $allowedOrigins, true)) {
            $origin = $originHeader;
        } elseif (! empty($allowedOrigins)) {
            $origin = $allowedOrigins[0];
        } elseif (config('app.frontend_url')) {
            $origin = config('app.frontend_url');
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-Impersonate-User-Id, X-Trace-Id, X-System-Session-Id, X-System-Current-Route, Idempotency-Key, Content-Range, X-Chunk-SHA256');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}
