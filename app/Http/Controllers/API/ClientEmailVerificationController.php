<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\MailService;
use App\Services\SystemEmails\EmailBrandingConfig;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\EmailHealthService;
use Illuminate\Http\RedirectResponse;
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
        private readonly MailService $mailService,
    ) {
    }

    public function __invoke(Request $request, User $user, string $hash): Response|RedirectResponse
    {
        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $user, $hash) {
            $fresh = \App\Services\Users\AccountSecurityMutation::lockUser($user->getKey());
            return $this->verifyLockedEmail($request, $fresh, $hash);
        }, 3);
        return is_array($result) ? $this->finishVerifiedEmail($result['user'], $result['verification_result']) : $result;
    }

    private function verifyLockedEmail(Request $request, User $user, string $hash): Response|array
    {
        $verificationResult = null;
        $token = $request->query('token');
        $expires = $this->clientEmailVerificationLinkService->resolveExpiryTimestamp($request->query('expires'));
        $signature = $request->query('signature');
        $signatureVersion = $request->query('signature_v');
        if (is_string($token) && trim($token) !== '') {
            $verificationResult = $this->clientEmailVerificationLinkService->consumeVerificationToken($user, $hash, $token);

            if (!$verificationResult->success) {
                $this->logVerificationFailure(
                    $request,
                    $user,
                    $verificationResult->reason,
                    null,
                    null,
                    $verificationResult->token?->id,
                );

                return response()->view('email_verification_result', $this->pageData(
                    'Verification link invalid',
                    $verificationResult->reason === 'hash_mismatch'
                        ? 'The verification link does not match the current account email. Please request a new verification email from the dashboard.'
                        : 'This verification link is invalid or has expired. Please request a new verification email from your dashboard.',
                    false,
                ), $verificationResult->reason === 'hash_mismatch' ? 422 : 403);
            }
        } else {
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
                'The verification link does not match the current account email. Please request a new verification email from the dashboard.',
                false,
            ), 422);
        }

        $this->emailHealthService->markVerified($user);
        UserActivityLog::record(
            $user,
            'email_verified',
            'Email verified',
            'The account holder confirmed their email address and can now receive normal dashboard notifications.'
        );

        return ['user' => $user, 'verification_result' => $verificationResult];
    }

    private function finishVerifiedEmail(User $user, ?\App\Services\Users\ClientEmailVerificationResult $verificationResult): Response|RedirectResponse
    {
        try {
            $sent = $this->mailService->sendClientEmailVerifiedEmail($user, [
            'verification_token_id' => $verificationResult?->token?->id ?? null,
            ]);
        } catch (\Throwable $exception) {
            $sent = false;
            Log::warning('Post-verification confirmation failed.', ['user_id' => $user->id, 'exception_class' => $exception::class]);
        }
        if (!$sent) {
            Log::warning('Failed to send post-verification confirmation email', [
                'user_id' => $user->id,
                'verification_token_id' => $verificationResult?->token?->id ?? null,
            ]);
        }

        if (($verificationResult?->token?->issued_context ?? null) === 'admin_account_created') {
            return redirect()->away($this->passwordCreationUrl($user));
        }

        return response()->view('email_verification_result', $this->pageData(
            'Email verified',
            'This account email address is now verified and ready for normal outbound communication.',
            true,
        ));
    }

    protected function passwordCreationUrl(User $user): string
    {
        return $this->mailService->generateStoredPasswordCreationLink($user, (string) $user->email);
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
            return 'legacy_signature_missing';
        }

        return 'legacy_signature_invalid';
    }

    protected function logVerificationFailure(
        Request $request,
        User $user,
        string $reason,
        ?int $expires,
        mixed $signatureVersion,
        ?int $tokenId = null,
    ): void {
        Log::warning('Client email verification failed', [
            'user_id' => $user->id,
            'failure_reason' => $reason,
            'verification_token_id' => $tokenId,
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
