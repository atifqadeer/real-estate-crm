<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\PlatformSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Platform Settings page values (database) override .env for the
        // Meta/LinkedIn/AI integrations. Guarded: during installs and
        // migrations the table may not exist yet — .env values then apply.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('platform_settings')) {
                app(\App\Services\PlatformSettings::class)->applyToConfig();
            }
        } catch (\Throwable) {
            // Database unavailable — keep env/config values.
        }
    }
}
