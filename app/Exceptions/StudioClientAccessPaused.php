<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

class StudioClientAccessPaused extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('AI Studio is not currently available for client accounts. Your saved work is retained.');
    }
}
