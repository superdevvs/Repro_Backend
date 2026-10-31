<?php

namespace App\Services\ListingStudio;

class StudioPaymentException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 409, public readonly array $data = [])
    {
        parent::__construct($message);
    }
}
