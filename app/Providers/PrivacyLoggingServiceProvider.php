<?php

namespace App\Providers;

use App\Logging\PrivacyLogManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class PrivacyLoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('log', fn ($app) => new PrivacyLogManager($app));
        Log::clearResolvedInstance('log');
    }
}
