<?php

namespace App\Providers;

use App\Support\Setting;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;

class SettingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->scoped(Setting::class, function (Application $app) {
            return new Setting();
        });

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        \App\Services\Logs\LogSettings::forget();
        $this->app->extend('queue.failer',fn($provider)=>new \App\Services\Logs\LogFailedJobProvider($provider));
        $this->app->singleton(\Laravel\Horizon\Contracts\JobRepository::class,\App\Services\Logs\LogHorizonRepository::class);
        // App URL is forced per-request via middleware (Octane-safe).
    }
}
