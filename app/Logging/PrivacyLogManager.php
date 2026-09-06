<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\StreamHandler;

class PrivacyLogManager extends \Illuminate\Log\LogManager
{
    protected function tap($name, Logger $logger)
    {
        $logger = parent::tap($name, $logger);
        (new PrivacyLogTap())($logger);
        return $logger;
    }

    protected function createEmergencyLogger()
    {
        $config = $this->configurationFor('emergency');
        $handler = new StreamHandler($config['path'] ?? $this->app->storagePath().'/logs/laravel.log', \Monolog\Level::Debug, true, 0660);
        $logger = new Logger(new \Monolog\Logger('laravel', $this->prepareHandlers([$handler])), $this->app['events']);
        (new PrivacyLogTap())($logger);
        return $logger;
    }
}
