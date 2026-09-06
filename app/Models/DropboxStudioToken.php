<?php

namespace App\Models;

use Illuminate\Support\Facades\Crypt;

/** Backward-compatible reads; new studio credentials are encrypted at rest. */
class DropboxStudioToken extends OauthToken
{
    protected $table = 'oauth_tokens';
    protected $hidden = ['access_token', 'refresh_token'];

    public function getAccessTokenAttribute(?string $value): ?string
    {
        return $this->decode($value);
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $this->encode($value);
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $this->decode($value);
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $this->encode($value);
    }

    private function encode(?string $value): ?string
    {
        return $value === null ? null : 'encrypted:v1:'.Crypt::encryptString($value);
    }

    private function decode(?string $value): ?string
    {
        return $value !== null && str_starts_with($value, 'encrypted:v1:')
            ? Crypt::decryptString(substr($value, 13)) : $value;
    }
}
