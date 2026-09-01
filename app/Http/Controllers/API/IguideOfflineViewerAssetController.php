<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\IguideOfflineViewerService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IguideOfflineViewerAssetController extends Controller
{
    public function __invoke(
        string $shootId,
        string $fileId,
        string $expires,
        string $signature,
        IguideOfflineViewerService $viewer,
        ?string $path = null
    ): StreamedResponse {
        $shootKey = $this->routeInteger($shootId);
        $fileKey = $this->routeInteger($fileId);
        $expiry = $this->routeInteger($expires);
        if ($shootKey === null || $fileKey === null || $expiry === null) {
            abort(403, 'This iGUIDE viewer link is invalid or has expired.');
        }

        return $viewer->streamAsset($shootKey, $fileKey, $expiry, $signature, $path);
    }

    private function routeInteger(string $value): ?int
    {
        if ($value === '' || ! ctype_digit($value) || str_starts_with($value, '0')) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($validated) && (string) $validated === $value ? $validated : null;
    }
}
