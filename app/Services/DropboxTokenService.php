<?php

namespace App\Services;

use RuntimeException;

/** @deprecated Compatibility for the paused recovery seeder; Dropbox is retired. */
class DropboxTokenService
{
    public function getValidAccessToken(): string
    {
        throw new RuntimeException('Dropbox integration has been retired.');
    }
}
