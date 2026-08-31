<?php

namespace App\Exceptions;

use App\Models\IguideOfflineUploadSession;
use RuntimeException;

class IguideOfflineUploadException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorType,
        public readonly ?IguideOfflineUploadSession $uploadSession = null,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
