<?php

use App\Models\User;

// F6: the client self-heals its push subscription on launch; these pin the
// server half — shared props on every page and last_sent_at on the dashboard.

test('shared props carry reminder state for the resync hook', function () {
    $user = User::factory()->create();
    $user->reminders()->create(['kind' => 'daily_nudge', 'schedule' => '17:00', 'enabled' => true]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('push.reminder_enabled', true)
            ->has('push.vapid_public_key'));
});

test('shared props handle guests without a reminder lookup', function () {
    $this->get('/login')
        ->assertInertia(fn ($page) => $page->where('push.reminder_enabled', false));
});

test('shared props report reminder disabled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('push.reminder_enabled', false));
});

test('dashboard reminder carries last_sent_at', function () {
    $user = User::factory()->create();
    $user->reminders()->create([
        'kind' => 'daily_nudge',
        'schedule' => '17:00',
        'enabled' => true,
        'last_sent_at' => now(),
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->whereNot('reminder.last_sent_at', null));
});
