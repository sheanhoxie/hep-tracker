<?php

use App\Models\Observation;
use App\Models\User;
use App\Models\WorkoutSession;
use Database\Seeders\HepProgramSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->patient = User::where('email', 'patient@example.com')->firstOrFail();
});

test('the dashboard shows due count, weekly progress, streak, and recent sessions', function () {
    $this->actingAs($this->patient)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('due_count', 13)
            ->where('weekly.done', 0)
            ->where('weekly.target', 15)
            ->where('streak_days', 0)
            ->has('recent_sessions', 0));
});

test('the library lists every exercise with thumb and video', function () {
    $this->actingAs($this->patient)->get('/library')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library')
            ->has('exercises', 22)
            ->whereNot('exercises.0.thumb', null)
            ->whereNot('exercises.0.video', null));
});

test('the home page redirects a logged-in user to today', function () {
    $this->actingAs($this->patient)->get('/')->assertRedirect(route('today'));
});

test('history lists sessions with pain, rpe, and note observations', function () {
    $session = WorkoutSession::create([
        'user_id' => $this->patient->id,
        'planned_date' => today(),
        'status' => 'done',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
    ]);
    foreach ([
        ['kind' => 'pain', 'numeric_value' => 3],
        ['kind' => 'rpe', 'numeric_value' => 7],
        ['kind' => 'note', 'text_value' => 'felt strong'],
    ] as $obs) {
        Observation::create([
            'user_id' => $this->patient->id,
            'workout_session_id' => $session->id,
            'client_key' => Str::uuid()->toString(),
            'kind' => $obs['kind'],
            'numeric_value' => $obs['numeric_value'] ?? null,
            'text_value' => $obs['text_value'] ?? null,
        ]);
    }

    $this->actingAs($this->patient)->get('/history')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('History')
            ->has('sessions', 1)
            ->where('sessions.0.id', $session->id)
            ->where('sessions.0.status', 'done')
            ->where('sessions.0.pain', 3)
            ->where('sessions.0.rpe', 7)
            ->where('sessions.0.note', 'felt strong')
            ->where('prev', null)
            ->where('next', null));
});

test('the dashboard exposes the pain series in chronological order', function () {
    $session = WorkoutSession::create([
        'user_id' => $this->patient->id,
        'planned_date' => today(),
        'status' => 'done',
    ]);
    Observation::create([
        'user_id' => $this->patient->id,
        'workout_session_id' => $session->id,
        'kind' => 'pain',
        'numeric_value' => 5,
        'occurred_at' => now()->subDay(),
    ]);
    Observation::create([
        'user_id' => $this->patient->id,
        'workout_session_id' => $session->id,
        'kind' => 'pain',
        'numeric_value' => 2,
        'occurred_at' => now(),
    ]);

    $this->actingAs($this->patient)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('pain_series', 2)
            ->where('pain_series.0.value', 5)
            ->where('pain_series.1.value', 2));
});

test('media is seeded once per exercise and kind', function () {
    $this->seed(HepProgramSeeder::class);

    expect(App\Models\ExerciseMedia::where('kind', 'video')->count())->toBe(22)
        ->and(App\Models\ExerciseMedia::where('kind', 'image')->count())->toBe(22);
});
