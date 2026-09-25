<?php

namespace App\Services\Studio\Providers;

use App\Exceptions\StudioProviderException;

/** Fixed messages only. Never include response bodies, image URLs, or the API key. */
class VirtualStagingAiException extends StudioProviderException {}
