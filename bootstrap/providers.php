<?php

return [
    App\Providers\PrivacyLoggingServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    // Registered last so its testing-environment bindings win over anything
    // bound above.
    App\Providers\MessagingSafetyServiceProvider::class,
];
