<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

final class UploadTargetValidationException extends ValidationException
{
    public string $errorType;
    public string $targetMessage;

    public static function reject(string $message, string $type): never
    {
        $error = static::withMessages(['shoot_service_id' => $message]);
        $error->errorType = $type;
        $error->targetMessage = $message;
        throw $error;
    }
}
