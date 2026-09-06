<?php

namespace App\Exceptions;

use RuntimeException;

/** A provider rejection that must not be retried automatically or expose the provider payload. */
class FalTerminalException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus)
    {
        $message = match ($httpStatus) {
            401, 403, 407 => 'fal.ai could not authorize this operation. Check the provider configuration.',
            402 => 'fal.ai could not run this operation because the provider account needs attention.',
            404, 410 => 'fal.ai no longer has this request. Retry to create a new request.',
            400, 413, 415, 422 => 'fal.ai rejected the media or generation settings. Prepare the media again before retrying.',
            default => 'fal.ai rejected this operation. Check the generation settings before retrying.',
        };

        parent::__construct($message.' (HTTP '.$httpStatus.').', $httpStatus);
    }

    public function canDiscardRequest(): bool
    {
        // Credentials, billing and access can recover while the existing request remains valid.
        return in_array($this->httpStatus, [400, 404, 410, 413, 415, 422], true);
    }
}
