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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

        if (!app()->environment('production') || app()->runningInConsole()) {
            return;
        }

        $publicStorage = public_path('storage');
        $storageTarget = storage_path('app/public');

        if (!file_exists($publicStorage)) {
            try {
                Artisan::call('storage:link');

                if (!file_exists($publicStorage)) {
                    Log::error('public/storage symlink missing after storage:link', [
                        'public_path' => $publicStorage,
                        'target_path' => $storageTarget,
                    ]);
                } else {
                    Log::info('public/storage symlink created automatically', [
                        'public_path' => $publicStorage,
                        'target_path' => $storageTarget,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to create public/storage symlink', [
                    'error' => $e->getMessage(),
                    'public_path' => $publicStorage,
                    'target_path' => $storageTarget,
                ]);
            }
        } elseif (!is_link($publicStorage)) {
            Log::warning('public/storage exists but is not a symlink', [
                'public_path' => $publicStorage,
                'target_path' => $storageTarget,
            ]);
        }
    }
}
