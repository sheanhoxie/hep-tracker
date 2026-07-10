<?php

use App\Models\User;
use App\Notifications\DailyNudge;
use Database\Seeders\HepProgramSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->patient = User::where('email', 'patient@example.com')->firstOrFail();
});

test('a push subscription is stored and upserts by endpoint', function () {
    $this->actingAs($this->patient)->postJson('/push-subscriptions', [
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'k1', 'auth' => 'a1'],
    ])->assertNoContent();

    $this->actingAs($this->patient)->postJson('/push-subscriptions', [
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'k2', 'auth' => 'a2'],
    ])->assertNoContent();

    expect($this->patient->pushSubscriptions()->count())->toBe(1)
        ->and($this->patient->pushSubscriptions()->sole()->public_key)->toBe('k2');
});

test('a push subscription is deleted by endpoint', function () {
    $this->patient->updatePushSubscription('https://push.example/abc', 'k1', 'a1');

    $this->actingAs($this->patient)->deleteJson('/push-subscriptions', [
        'endpoint' => 'https://push.example/abc',
    ])->assertNoContent();

    expect($this->patient->pushSubscriptions()->count())->toBe(0);
});

test('reminder settings update the seeded daily nudge', function () {
    $this->actingAs($this->patient)
        ->from('/dashboard')
        ->put('/reminder-settings', ['enabled' => true, 'time' => '18:30'])
        ->assertRedirect('/dashboard');

    $reminder = $this->patient->reminders()->where('kind', 'daily_nudge')->sole();
    expect($reminder->schedule)->toBe('18:30')
        ->and($reminder->enabled)->toBeTrue();
});

test('reminders:send nudges once per day when work is due after the scheduled time', function () {
    Notification::fake();
    $this->travelTo(today()->setTime(17, 30));

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertSentTo($this->patient, DailyNudge::class, fn (DailyNudge $n): bool => $n->dueCount === 13);
    expect($this->patient->reminders()->sole()->last_sent_at)->not->toBeNull();

    $this->artisan('reminders:send')->assertSuccessful();
    Notification::assertSentToTimes($this->patient, DailyNudge::class, 1);
});

test('reminders:send stays quiet before the scheduled time', function () {
    Notification::fake();
    $this->travelTo(today()->setTime(12, 0));

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNothingSent();
    expect($this->patient->reminders()->sole()->last_sent_at)->toBeNull();
});

test('reminders:send skips a disabled reminder', function () {
    Notification::fake();
    $this->patient->reminders()->update(['enabled' => false]);
    $this->travelTo(today()->setTime(17, 30));

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNothingSent();
});
