<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootShareLink;
use App\Models\ShortLink;
use App\Services\IguideOfflineViewerService;
use App\Services\Payments\PublicPaymentAccessTokenService;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\ShortLinks\ShortLinkService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShortLinkController extends Controller
{
    private const IGUIDE_CACHE_SECONDS = 300;

    public function __invoke(
        string $code,
        ShortLinkService $shortLinks,
        IguideOfflineViewerService $iguideViewer,
        ShootMediaArchiveService $mediaArchives,
        PublicPaymentAccessTokenService $paymentTokens,
        ?string $path = null
    ): StreamedResponse|RedirectResponse {
        $link = $shortLinks->findActive($code);
        if ($link === null) {
            abort(404);
        }

        return match ($link->type) {
            ShortLink::TYPE_IGUIDE_OFFLINE_VIEWER => $iguideViewer->streamReadyPackage(
                (int) $link->target_id,
                null,
                $path,
                self::IGUIDE_CACHE_SECONDS
            ),
            ShortLink::TYPE_SHARE_DOWNLOAD => $this->redirectShare($link, $path),
            ShortLink::TYPE_MEDIA_ZIP => $this->redirectMediaZip($link, $path, $mediaArchives),
            ShortLink::TYPE_PAYMENT => $this->redirectPayment($link, $path, $paymentTokens),
            default => abort(404),
        };
    }

    private function redirectShare(ShortLink $link, ?string $path): RedirectResponse
    {
        $this->assertBareCode($path);
        $share = ShootShareLink::query()->find($link->target_id);
        if ($share === null || ! $share->isActive()) {
            abort(404);
        }

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return redirect()->away("{$frontend}/share/{$share->public_token}");
    }

    private function redirectMediaZip(
        ShortLink $link,
        ?string $path,
        ShootMediaArchiveService $mediaArchives
    ): RedirectResponse {
        $this->assertBareCode($path);
        $shoot = Shoot::query()->find($link->target_id);
        if ($shoot === null) {
            abort(404);
        }

        [$type, $size] = array_pad(explode(':', $link->target_key, 2), 2, 'original');

        return redirect()->away($mediaArchives->buildCanonicalPublicDownloadUrl($shoot, $type, $size));
    }

    private function redirectPayment(
        ShortLink $link,
        ?string $path,
        PublicPaymentAccessTokenService $paymentTokens
    ): RedirectResponse {
        $this->assertBareCode($path);
        $shoot = Shoot::query()->find($link->target_id);
        if ($shoot === null) {
            abort(404);
        }

        $token = $paymentTokens->ensureActiveToken($shoot);
        if (! $token->isActive()) {
            abort(404);
        }

        return redirect()->away($paymentTokens->buildPublicUrlFromToken($token));
    }

    private function assertBareCode(?string $path): void
    {
        if ($path !== null && $path !== '') {
            abort(404);
        }
    }
}
