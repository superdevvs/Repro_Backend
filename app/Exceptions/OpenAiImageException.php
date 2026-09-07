<?php

namespace App\Exceptions;

use RuntimeException;

/** Safe for job status: never retain provider bodies, input bytes, or transport exceptions. */
class OpenAiImageException extends RuntimeException
{
    public function __construct(
        public readonly ?int $httpStatus = null,
        public readonly bool $ambiguous = false,
        public readonly string $reason = 'provider_rejected',
    ) {
        parent::__construct(match ($reason) {
            'not_configured' => 'The backup image provider is not configured.',
            'unsupported_model' => 'The backup image model is not supported.',
            'invalid_image' => 'This image could not be prepared for editing.',
            'invalid_ratio' => 'This image format is not supported for extension.',
            'invalid_prompt' => 'Add a description of the requested image changes.',
            'invalid_result' => 'The backup image provider did not return a usable image.',
            'moderation_blocked' => 'The image request was blocked. Review the image and instructions before trying again.',
            'transport' => 'The backup image request could not be confirmed. Check its status before retrying.',
            default => match ($httpStatus) {
                401, 403 => 'The backup image provider could not authorize this request.',
                402, 429 => 'The backup image provider is temporarily unavailable. Try again later.',
                default => 'The backup image provider could not complete this edit.',
            },
        });
    }

    public function isTerminal(): bool
    {
        return ! $this->ambiguous && ($this->httpStatus === null
            || ($this->httpStatus >= 400 && $this->httpStatus < 500
                && ! in_array($this->httpStatus, [408, 409, 425, 429], true)));
    }
}
