<?php

use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reminders:send')->everyFifteenMinutes();

// Dead-man's switches (DEPLOY.md F4). Silence = the alarm: healthchecks.io
// pages when pings stop, so these only run where the URLs are configured.
Schedule::call(function () {
    $url = config('services.healthchecks.scheduler');

    if (is_string($url) && $url !== '') {
        Http::timeout(10)->retry(3, 1000)->get($url);
    }
})->everyFifteenMinutes()->name('scheduler-heartbeat');

Schedule::call(function () {
    if (config('services.healthchecks.queue')) {
        QueueHeartbeat::dispatch();
    }
})->everyFifteenMinutes()->name('queue-heartbeat');
