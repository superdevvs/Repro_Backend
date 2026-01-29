<?php

namespace App\Providers;

use App\Listeners\TriggerAccountVerifiedAutomation;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Verified::class => [
            TriggerAccountVerifiedAutomation::class,
        ],
    ];
}
