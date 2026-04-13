<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\EmailHealthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ClientEmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailHealthService $emailHealthService,
    ) {
    }

    public function __invoke(Request $request, User $user, string $hash): Response
    {
        if (!$request->hasValidRelativeSignature() && !$request->hasValidSignature()) {
            return response()->view('email_verification_result', $this->pageData(
                'Verification link invalid',
                'This verification link is invalid or has expired. Please request a new verification email from your dashboard.',
                false,
            ), 403);
        }

        $expectedHash = sha1(Str::lower((string) $user->email));

        if (!hash_equals($expectedHash, $hash)) {
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

    /**
     * @return array<string, mixed>
     */
    protected function pageData(string $title, string $message, bool $success): array
    {
        return [
            'title' => $title,
            'message' => $message,
            'success' => $success,
            'dashboardUrl' => rtrim((string) Config::get('app.frontend_url', 'https://reprodashboard.com'), '/'),
        ];
    }
}
