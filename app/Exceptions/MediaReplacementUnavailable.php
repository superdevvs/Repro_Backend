<?php

namespace App\Exceptions;

/** A retryable replacement failure that preserves the previous file. */
class MediaReplacementUnavailable extends \RuntimeException {}
