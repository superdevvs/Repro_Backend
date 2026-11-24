<?php

namespace App\Http\Controllers\API\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\EmailSuppression;
use App\Services\Crm\CrmNormalizer;
use App\Services\Crm\Email\CrmSuppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public unsubscribe page and the one-click unsubscribe used by mail apps. No login. */
class PublicCrmUnsubscribeController extends Controller
{
    public function __construct(private readonly CrmSuppression $suppression) {}

    private function decode(string $token): array
    {
        $data = $this->suppression->decode($token);
        abort_unless($data !== null, 404, 'This unsubscribe link is not valid.');

        return $data;
    }

    public function show(string $token): JsonResponse
    {
        $data = $this->decode($token);
        $row = EmailSuppression::query()->where('email_normalized', CrmNormalizer::email($data['email']))->first();

        return response()->json([
            'email' => $data['email'],
            'unsubscribed' => $row !== null,
            'can_resubscribe' => $row?->reason === 'unsubscribe',
        ]);
    }

    public function confirm(string $token): JsonResponse
    {
        $data = $this->decode($token);
        $this->suppression->suppress($data['email'], 'unsubscribe', 'link');

        return response()->json(['email' => $data['email'], 'unsubscribed' => true, 'can_resubscribe' => true]);
    }

    /** RFC 8058 one-click: the mail app posts here directly. */
    public function oneClick(Request $request, string $token): JsonResponse
    {
        $data = $this->decode($token);
        $this->suppression->suppress($data['email'], 'unsubscribe', 'one_click');

        return response()->json(['unsubscribed' => true]);
    }

    public function resubscribe(string $token): JsonResponse
    {
        $data = $this->decode($token);
        $ok = $this->suppression->resubscribe($data['email']);

        return response()->json(['email' => $data['email'], 'unsubscribed' => ! $ok, 'can_resubscribe' => false], $ok ? 200 : 422);
    }
}
