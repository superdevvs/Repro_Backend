<?php

namespace App\Exceptions\Messaging;

/** The provider explicitly rejected the request before accepting the email. */
class EmailProviderRejectedException extends \RuntimeException
{
}
