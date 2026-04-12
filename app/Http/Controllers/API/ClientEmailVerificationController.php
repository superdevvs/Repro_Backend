<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\EmailHealthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ClientEmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailHealthService $emailHealthService,
    ) {
    }

    public function __invoke(Request $request, User $user, string $hash): Response
    {
        $expectedHash = sha1(Str::lower((string) $user->email));

        if (!hash_equals($expectedHash, $hash)) {
            return response($this->renderPage(
                'Verification link invalid',
                'The verification link does not match the current client email. Please request a new verification email from the dashboard.',
                false,
            ), 422);
        }

        $this->emailHealthService->markVerified($user);

        return response($this->renderPage(
            'Email verified',
            'This client email address is now verified and ready for normal outbound communication.',
            true,
        ));
    }

    protected function renderPage(string $title, string $message, bool $success): string
    {
        $accent = $success ? '#0f766e' : '#b91c1c';
        $surface = $success ? '#ecfdf5' : '#fef2f2';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>
  <style>
    body { margin:0; font-family: Arial, sans-serif; background:#f8fafc; color:#0f172a; }
    main { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .card { max-width:560px; width:100%; background:white; border:1px solid #e2e8f0; border-radius:16px; padding:32px; box-shadow:0 18px 40px rgba(15,23,42,0.08); }
    .badge { display:inline-block; padding:6px 10px; border-radius:999px; background:{$surface}; color:{$accent}; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; }
    h1 { margin:16px 0 12px; font-size:28px; line-height:1.2; }
    p { margin:0; font-size:16px; line-height:1.6; color:#334155; }
  </style>
</head>
<body>
  <main>
    <section class="card">
      <span class="badge">R/E Pro Photos</span>
      <h1>{$title}</h1>
      <p>{$message}</p>
    </section>
  </main>
</body>
</html>
HTML;
    }
}
