<?php

namespace App\Services\Users;

use App\Models\ClientEmailVerificationToken;

class ClientEmailVerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $reason,
        public readonly ?ClientEmailVerificationToken $token = null,
    ) {
    }
}
