<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 2: re-exchange long-lived Meta user tokens before they expire.
Schedule::job(new \App\Jobs\RefreshMetaTokens)->daily()->name('refresh-meta-tokens')->withoutOverlapping();

// Phase 3: dispatch due scheduled posts + retry sweep, every minute.
Schedule::job(new \App\Jobs\ProcessScheduledPosts)->everyMinute()->name('process-scheduled-posts')->withoutOverlapping();
