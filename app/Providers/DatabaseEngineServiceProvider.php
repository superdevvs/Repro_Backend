<?php

namespace App\Providers;

use App\Support\DatabaseEngine;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Applies the runtime engine check (DB_EXPECTED_ENGINE) to the default connection. */
class DatabaseEngineServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            DatabaseEngine::guard($event->connection);
        });

        // A connection opened before this provider booted is checked too.
        foreach ($this->app['db']->getConnections() as $connection) {
            DatabaseEngine::guard($connection);
        }
    }
}
