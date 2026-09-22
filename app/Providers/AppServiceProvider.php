<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Observers\ShootCompensationObserver;
use App\Observers\ShootFileObserver;
use App\Observers\ShootObserver;
use App\Observers\ShootServiceObserver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\View::composer('emails.*', \App\Services\SystemEmails\EmailViewComposer::class);

        // Resolve soft-deleted users during token authentication so they are explicitly rejected
        // (Req 17.5) rather than being treated as an absent/unknown user.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        \App\Models\User::observe(\App\Observers\UserEmailVerificationObserver::class);

        // Explicit route model binding for ShootFile
        Route::model('file', ShootFile::class);
        Shoot::observe(ShootObserver::class);
        ShootService::observe(ShootServiceObserver::class);
        ShootCompensation::observe(ShootCompensationObserver::class);
        ShootFile::observe(ShootFileObserver::class);

        // Deployment provisions and verifies public/storage as the deploy user.
        // PHP-FPM must never mutate the application public directory on boot.
    }
}
