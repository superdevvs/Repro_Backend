<?php

return [
    App\Providers\PrivacyLoggingServiceProvider::class,
    App\Providers\DatabaseEngineServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\ServerMonitorServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\CrmServiceProvider::class,
    // Registered last so its testing-environment bindings win over anything
    // bound above.
    App\Providers\MessagingSafetyServiceProvider::class,
];
