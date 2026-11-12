<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 2: re-exchange long-lived Meta user tokens before they expire.
Schedule::job(new \App\Jobs\RefreshMetaTokens)->daily()->name('refresh-meta-tokens')->withoutOverlapping();
