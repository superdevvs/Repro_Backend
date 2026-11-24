<?php

namespace App\Providers;

use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmPresenter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Sales CRM wiring. Per-request services are scoped so cached permission
 * answers and user lookups never carry over between requests or tests.
 */
class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CrmAccess::class);
        $this->app->scoped(CrmPresenter::class);
        $this->app->scoped(\App\Services\Crm\CrmRepSuggester::class);
    }

    public function boot(): void
    {
        foreach (self::listeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    /** @return array<class-string, array<int, class-string>> */
    public static function listeners(): array
    {
        $map = [];
        foreach ([
            // Kept outside app/Listeners so Laravel's listener discovery does not register them a second time.
            \App\Services\Crm\Listeners\StopDripOnStageChange::class => \App\Events\Crm\CrmContactStageChanged::class,
            \App\Services\Crm\Listeners\CancelEventsWhenLost::class => \App\Events\Crm\CrmContactStageChanged::class,
        ] as $listener => $event) {
            if (class_exists($listener)) {
                $map[$event][] = $listener;
            }
        }

        return $map;
    }
}
