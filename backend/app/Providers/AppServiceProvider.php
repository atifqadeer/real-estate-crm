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
        // Platform Settings page values (database) are the ONLY source of
        // Meta/LinkedIn/AI credentials — there are no env variables for
        // them. Guarded: during installs and migrations the table may not
        // exist yet — integrations then simply report "not configured".
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('platform_settings')) {
                app(\App\Services\PlatformSettings::class)->applyToConfig();
            }
        } catch (\Throwable) {
            // Database unavailable — integrations stay unconfigured.
        }
    }
}
