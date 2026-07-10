<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

// Dead-man's switch for the queue worker (DEPLOY.md F4): this job pinging
// healthchecks proves jobs actually process — systemd showing a live worker
// process does not.
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $url = config('services.healthchecks.queue');

        if (is_string($url) && $url !== '') {
            Http::timeout(10)->retry(3, 1000)->get($url);
        }
    }
}
