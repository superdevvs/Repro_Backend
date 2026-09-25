<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AutoenhanceDownload
{
    /** Follow signed result redirects separately so the API credential never reaches S3. */
    public function resolve(Response $response, int $timeout = 120): Response
    {
        for ($redirects = 0; $response->redirect() && $redirects < 3; $redirects++) {
            $url = $response->header('Location');
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https'
                || ! preg_match('/^[a-z0-9.-]+\.s3(?:-accelerate|[.-][a-z0-9-]+)?\.amazonaws\.com$/i', $host)
                || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PORT)) {
                throw new RuntimeException('The photo service returned an unsupported download location.');
            }
            $response = Http::timeout($timeout)->withOptions(['allow_redirects' => false])->get($url);
        }

        return $response;
    }
}
