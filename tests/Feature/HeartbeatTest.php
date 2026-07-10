<?php

use App\Jobs\QueueHeartbeat;
use Illuminate\Support\Facades\Http;

test('queue heartbeat pings healthchecks when configured', function () {
    config(['services.healthchecks.queue' => 'https://hc-ping.com/test-uuid']);
    Http::fake(['hc-ping.com/*' => Http::response('OK')]);

    (new QueueHeartbeat)->handle();

    Http::assertSentCount(1);
});

test('queue heartbeat is silent when unconfigured', function () {
    config(['services.healthchecks.queue' => null]);
    Http::fake();

    (new QueueHeartbeat)->handle();

    Http::assertNothingSent();
});

test('heartbeat schedules are registered every fifteen minutes', function () {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events());

    $names = $events->map(fn ($e) => $e->description ?? $e->command);

    expect($names->contains('scheduler-heartbeat'))->toBeTrue()
        ->and($names->contains('queue-heartbeat'))->toBeTrue();

    $events->filter(fn ($e) => in_array($e->description, ['scheduler-heartbeat', 'queue-heartbeat']))
        ->each(fn ($e) => expect($e->expression)->toBe('*/15 * * * *'));
});
