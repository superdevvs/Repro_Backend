<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SystemEmails\EmailBrandingConfig;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\EmailHealthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ClientEmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailHealthService $emailHealthService,
        private readonly ClientEmailVerificationLinkService $clientEmailVerificationLinkService,
        private readonly EmailBrandingConfig $emailBrandingConfig,
    ) {
    }

    public function __invoke(Request $request, User $user, string $hash): Response
    {
        $expires = $this->clientEmailVerificationLinkService->resolveExpiryTimestamp($request->query('expires'));
        $signature = $request->query('signature');
        $signatureVersion = $request->query('signature_v');
        $legacySignatureValid = $request->hasValidRelativeSignature() || $request->hasValidSignature();
        $hmacSignatureValid = $this->clientEmailVerificationLinkService->hasValidSignature(
            $user,
            $hash,
            $expires,
            is_string($signature) ? $signature : null,
        );

        if (!$legacySignatureValid && !$hmacSignatureValid) {
            $this->logVerificationFailure(
                $request,
                $user,
                $this->resolveFailureReason($request, $expires, $signatureVersion),
                $expires,
                $signatureVersion,
            );

            return response()->view('email_verification_result', $this->pageData(
                'Verification link invalid',
                'This verification link is invalid or has expired. Please request a new verification email from your dashboard.',
                false,
            ), 403);
        }

        if (!$this->clientEmailVerificationLinkService->hasExpectedHash($user, $hash)) {
            $this->logVerificationFailure(
                $request,
                $user,
                'hash_mismatch',
                $expires,
                $signatureVersion,
            );

            return response()->view('email_verification_result', $this->pageData(
                'Verification link invalid',
                'The verification link does not match the current client email. Please request a new verification email from the dashboard.',
                false,
            ), 422);
        }

        $this->emailHealthService->markVerified($user);

        return response()->view('email_verification_result', $this->pageData(
            'Email verified',
            'This client email address is now verified and ready for normal outbound communication.',
            true,
        ));
    }

    protected function resolveFailureReason(Request $request, ?int $expires, mixed $signatureVersion): string
    {
        if ($signatureVersion === ClientEmailVerificationLinkService::SIGNATURE_VERSION) {
            if ($expires === null) {
                return 'missing_or_invalid_expiry';
            }

            if ($this->clientEmailVerificationLinkService->isExpired($expires)) {
                return 'expired';
            }

            return 'invalid_signature';
        }

        if ($expires !== null && $this->clientEmailVerificationLinkService->isExpired($expires)) {
            return 'expired';
        }

        if (!$request->query->has('signature')) {
            return 'missing_signature';
        }

        return 'invalid_signature';
    }

    protected function logVerificationFailure(
        Request $request,
        User $user,
        string $reason,
        ?int $expires,
        mixed $signatureVersion,
    ): void {
        Log::warning('Client email verification failed', [
            'user_id' => $user->id,
            'failure_reason' => $reason,
            'signature_version' => is_scalar($signatureVersion) ? (string) $signatureVersion : null,
            'expires' => $expires,
            'request_host' => $request->getHost(),
            'request_path' => '/' . ltrim($request->path(), '/'),
            'request_url' => $request->url(),
            'forwarded_host' => $request->headers->get('x-forwarded-host'),
            'forwarded_proto' => $request->headers->get('x-forwarded-proto'),
            'forwarded_prefix' => $request->headers->get('x-forwarded-prefix'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageData(string $title, string $message, bool $success): array
    {
        $branding = $this->emailBrandingConfig->defaults();

        return [
            'title' => $title,
            'message' => $message,
            'success' => $success,
            'dashboardUrl' => rtrim((string) Config::get('app.frontend_url', 'https://reprodashboard.com'), '/'),
            'branding' => $branding,
        ];
    }
}
