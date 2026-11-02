<?php

namespace App\Providers;

use App\Support\SemesterStore;
use App\Support\SettingsStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsStore::class);
        $this->app->singleton(SemesterStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
