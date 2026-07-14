<?php

use App\Models\Observation;
use App\Models\SetEvent;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\SessionService;
use Database\Seeders\HepProgramSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->patient = User::where('email', 'patient@example.com')->firstOrFail();
    $this->version = $this->patient->programs()->firstOrFail()
        ->currentVersion()->with('prescriptions.exercise')->firstOrFail();
});

function startSession($test): WorkoutSession
{
    $test->actingAs($test->patient)->post('/sessions');

    return WorkoutSession::latest('id')->firstOrFail();
}

test('starting a session materializes the due queue', function () {
    $response = $this->actingAs($this->patient)->post('/sessions');

    $session = WorkoutSession::firstOrFail();
    $response->assertRedirect(route('sessions.show', $session));

    expect($session->status)->toBe('active')
        ->and($session->started_at)->not->toBeNull()
        ->and($session->program_version_id)->toBe($this->version->id)
        ->and($session->items)->toHaveCount(13)
        ->and($session->items->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($session->items->pluck('sort_order')->all())->toBe(range(0, 12));
});

test('starting again the same day resumes the open session', function () {
    $first = startSession($this);
    $this->actingAs($this->patient)->post('/sessions')
        ->assertRedirect(route('sessions.show', $first));

    expect(WorkoutSession::count())->toBe(1)
        ->and($first->items()->count())->toBe(13);
});

test('items done in a finished session drop out of the next one', function () {
    $first = startSession($this);
    $item = $first->items()->firstOrFail();

    $this->actingAs($this->patient)
        ->patch("/sessions/{$first->id}/items/{$item->id}", ['status' => 'done'])
        ->assertRedirect();
    $this->actingAs($this->patient)
        ->patch("/sessions/{$first->id}", ['status' => 'done'])
        ->assertRedirect();

    $second = startSession($this);

    expect($second->id)->not->toBe($first->id)
        ->and($second->items)->toHaveCount(12)
        ->and($second->items->pluck('prescription_id'))->not->toContain($item->prescription_id);
});

test('the set-event endpoint dedupes on client_key', function () {
    $session = startSession($this);
    $item = $session->items()->firstOrFail();
    $payload = [
        'client_key' => (string) Str::uuid(),
        'set_no' => 1,
        'reps_done' => 8,
    ];

    $this->actingAs($this->patient)
        ->post("/sessions/{$session->id}/items/{$item->id}/set-events", $payload)
        ->assertRedirect();
    $this->actingAs($this->patient)
        ->post("/sessions/{$session->id}/items/{$item->id}/set-events", $payload)
        ->assertRedirect();

    expect(SetEvent::count())->toBe(1);
});

test('a set event without a client_key is rejected', function () {
    $session = startSession($this);
    $item = $session->items()->firstOrFail();

    $this->actingAs($this->patient)
        ->from("/sessions/{$session->id}")
        ->post("/sessions/{$session->id}/items/{$item->id}/set-events", ['set_no' => 1])
        ->assertInvalid(['client_key']);
});

test('skipping an item stores the reason', function () {
    $session = startSession($this);
    $item = $session->items()->firstOrFail();

    $this->actingAs($this->patient)
        ->patch("/sessions/{$session->id}/items/{$item->id}", [
            'status' => 'skipped',
            'skip_reason' => 'knee flared up',
        ])
        ->assertRedirect();

    $item->refresh();
    expect($item->status)->toBe('skipped')
        ->and($item->skip_reason)->toBe('knee flared up');
});

test('reordering rewrites the queue order', function () {
    $session = startSession($this);
    $reversed = $session->items->pluck('id')->reverse()->values();

    $this->actingAs($this->patient)
        ->put("/sessions/{$session->id}/items-order", ['item_ids' => $reversed->all()])
        ->assertRedirect();

    expect($session->items()->get()->pluck('id')->all())->toBe($reversed->all());
});

test('completing a session stamps ended_at', function () {
    $session = startSession($this);

    $this->actingAs($this->patient)
        ->patch("/sessions/{$session->id}", ['status' => 'done'])
        ->assertRedirect();

    $session->refresh();
    expect($session->status)->toBe('done')
        ->and($session->ended_at)->not->toBeNull();
});

test('observations attach at session or item grain and dedupe on client_key', function () {
    $session = startSession($this);
    $item = $session->items()->firstOrFail();

    $sessionGrain = [
        'client_key' => (string) Str::uuid(),
        'kind' => 'pain',
        'numeric_value' => 3,
    ];
    $this->actingAs($this->patient)
        ->post("/sessions/{$session->id}/observations", $sessionGrain)
        ->assertRedirect();
    $this->actingAs($this->patient)
        ->post("/sessions/{$session->id}/observations", $sessionGrain)
        ->assertRedirect();

    $this->actingAs($this->patient)
        ->post("/sessions/{$session->id}/observations", [
            'client_key' => (string) Str::uuid(),
            'workout_session_item_id' => $item->id,
            'kind' => 'note',
            'text_value' => 'felt easier today',
        ])
        ->assertRedirect();

    expect(Observation::count())->toBe(2)
        ->and(Observation::whereNull('workout_session_item_id')->count())->toBe(1)
        ->and(Observation::where('workout_session_item_id', $item->id)->count())->toBe(1);
});

test('an observation on an item from another session is rejected', function () {
    $session = startSession($this);

    $this->actingAs($this->patient)
        ->from("/sessions/{$session->id}")
        ->post("/sessions/{$session->id}/observations", [
            'client_key' => (string) Str::uuid(),
            'workout_session_item_id' => 999999,
            'kind' => 'pain',
            'numeric_value' => 2,
        ])
        ->assertInvalid(['workout_session_item_id']);
});

test('another user cannot reach the session', function () {
    $session = startSession($this);
    $item = $session->items()->firstOrFail();
    $mallory = User::factory()->create();

    $this->actingAs($mallory)->get("/sessions/{$session->id}")->assertNotFound();
    $this->actingAs($mallory)->patch("/sessions/{$session->id}", ['status' => 'done'])->assertNotFound();
    $this->actingAs($mallory)
        ->patch("/sessions/{$session->id}/items/{$item->id}", ['status' => 'done'])
        ->assertNotFound();
});

test('the set queue expands per_side and respects alternating laterality', function () {
    $bySlug = fn (string $slug) => $this->version->prescriptions
        ->first(fn ($p) => $p->exercise->slug === $slug);

    $heelRaise = SessionService::expandSetQueue($bySlug('heel-raise-eccentric'));
    expect($heelRaise)->toHaveCount(6)
        ->and($heelRaise[0]['side'])->toBe('left')
        ->and($heelRaise[1]['side'])->toBe('right')
        ->and($heelRaise[0]['set_no'])->toBe(1)
        ->and($heelRaise[5]['set_no'])->toBe(3);

    $quadruped = SessionService::expandSetQueue($bySlug('quadruped-leg-lift'));
    expect($quadruped)->toHaveCount(3)
        ->and(collect($quadruped)->pluck('side')->unique()->all())->toBe([null]);

    $plank = SessionService::expandSetQueue($bySlug('plank-ball'));
    expect($plank)->toHaveCount(2)
        ->and($plank[0]['hold_sec'])->toBe(45);
});

test('the session page renders the queue', function () {
    $session = startSession($this);

    $this->actingAs($this->patient)
        ->get("/sessions/{$session->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Session')
            ->where('session.id', $session->id)
            ->has('items', 13)
            ->has('items.0.set_queue')
            ->has('items.0.exercise.steps'));
});
