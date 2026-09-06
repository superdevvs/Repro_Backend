<?php

namespace App\Exceptions;

/** A reviewed application rule; never wrap provider or database exception text. */
class PublicBusinessRuleException extends \InvalidArgumentException
{
}
