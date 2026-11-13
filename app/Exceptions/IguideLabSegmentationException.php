<?php

namespace App\Exceptions;

use RuntimeException;

/** A definite SAM outcome that cannot improve by polling the same receipt. */
class IguideLabSegmentationException extends RuntimeException {}
