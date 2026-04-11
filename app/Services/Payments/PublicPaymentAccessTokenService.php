<?php

namespace App\Services\Payments;

use App\Models\PublicPaymentAccessToken;
use App\Models\Shoot;

class PublicPaymentAccessTokenService
{
    private const DEFAULT_FRONTEND_URL = 'https://reprodashboard.com';

    public function ensureActiveToken(Shoot $shoot, ?int $createdBy = null): PublicPaymentAccessToken
    {
        $existing = $shoot->publicPaymentAccessTokens()
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return $shoot->publicPaymentAccessTokens()->create([
            'created_by' => $createdBy,
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function buildPublicUrl(Shoot $shoot, ?int $createdBy = null): string
    {
        $token = $this->ensureActiveToken($shoot, $createdBy);

        return $this->buildPublicUrlFromToken($token);
    }

    public function buildPublicUrlFromToken(PublicPaymentAccessToken $token): string
    {
        $frontendUrl = rtrim($this->resolveFrontendUrl(), '/');

        return "{$frontendUrl}/payment/{$token->token}";
    }

    public function findToken(string $token): ?PublicPaymentAccessToken
    {
        return PublicPaymentAccessToken::query()
            ->with(['shoot.client', 'shoot.services', 'shoot.payments'])
            ->where('token', $token)
            ->first();
    }

    public function resolveAccessibleToken(string $token): ?PublicPaymentAccessToken
    {
        $accessToken = $this->findToken($token);
        if (!$accessToken) {
            return null;
        }

        $shoot = $accessToken->shoot;
        if (!$shoot) {
            return null;
        }

        $summary = $shoot->syncPaymentStatusFromRecords($shoot->payment_type ?: 'stripe');
        if (($summary['remaining_balance'] ?? 0) <= 0) {
            $accessToken->revoke();
            return null;
        }

        if (!$accessToken->isActive()) {
            return null;
        }

        return $accessToken;
    }

    public function revokeTokensForShoot(Shoot $shoot): void
    {
        $shoot->publicPaymentAccessTokens()
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
            ]);
    }

    private function resolveFrontendUrl(): string
    {
        try {
            $frontendUrl = (string) config('app.frontend_url', config('app.url', self::DEFAULT_FRONTEND_URL));
        } catch (\Throwable $exception) {
            return self::DEFAULT_FRONTEND_URL;
        }

        $frontendUrl = trim($frontendUrl);

        return $frontendUrl !== '' ? $frontendUrl : self::DEFAULT_FRONTEND_URL;
    }
}
