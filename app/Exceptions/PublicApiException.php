<?php

namespace App\Exceptions;

/** A reviewed business failure. Never pass a provider/SQL exception message here. */
class PublicApiException extends \Symfony\Component\HttpKernel\Exception\HttpException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'request_failed',
        public readonly int $httpStatus = 422,
        public readonly array $publicDetails = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($httpStatus, $message, $previous);
    }
}
