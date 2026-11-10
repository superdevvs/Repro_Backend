<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AryeoApiAsset;
use App\Services\Aryeo\Api\FrozenOriginals;
use App\Services\Aryeo\Api\Grants;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AryeoDownloadController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        $asset = AryeoApiAsset::where('grant_hash', hash('sha256', $token))->firstOrFail();
        app(Grants::class)->authorize($asset);
        $expected = $asset->source['content'];
        $size = $expected['bytes'];
        $start = 0;
        $end = $size - 1;
        $range = $request->header('Range');
        if ($range) {
            abort_unless(preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $m) && ($m[1] !== '' || $m[2] !== ''), 416);
            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $end : min($end, (int) $m[2]);
            }
            abort_unless($start <= $end && $start < $size, 416);
        }
        // A private copy written once from the approved revision prevents a
        // source-file replacement from changing bytes during a download.
        app(FrozenOriginals::class)->verify($asset);
        $path = app(FrozenOriginals::class)->path($asset);
        $headers = ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) ($end - $start + 1),
            'Cache-Control' => 'private, no-store', 'Accept-Ranges' => 'bytes', 'Referrer-Policy' => 'no-referrer',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', basename($asset->source['filename']), 'original')];
        if ($range) {
            $headers['Content-Range'] = "bytes $start-$end/$size";
        }
        if ($request->isMethod('HEAD')) {
            return response('', $range ? 206 : 200, $headers);
        }

        return response()->stream(function () use ($asset, $path, $expected, $start, $end) {
            if (hash_file('sha256', $path) !== $expected['sha256'] || filesize($path) !== $expected['bytes']) {
                return;
            }
            $stream = fopen($path, 'rb');
            if (! $stream) {
                return;
            }
            try {
                fseek($stream, $start);
                $remaining = $end - $start + 1;
                $checked = 0;
                while ($remaining > 0 && ! feof($stream) && ! connection_aborted()) {
                    if (microtime(true) - $checked >= 1) {
                        try {
                            app(Grants::class)->authorize($asset);
                        } catch (\Throwable) {
                            break;
                        }
                        $checked = microtime(true);
                    }
                    $chunk = fread($stream, min(1048576, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
        }, $range ? 206 : 200, $headers);
    }
}
