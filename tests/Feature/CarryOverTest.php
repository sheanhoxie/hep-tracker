<?php

use App\Models\Exercise;
use App\Models\Prescription;
use App\Models\ProgramVersion;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionItem;
use App\Services\ProgramRevisionService;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Database\Seeders\HepProgramSeeder;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->patient = User::where('email', 'patient@example.com')->firstOrFail();
    $this->program = $this->patient->programs()->firstOrFail();
    $this->schedule = app(ScheduleService::class);
    $this->revisions = app(ProgramRevisionService::class);
});

/** @return list<array<string, mixed>> */
function copyRows(ProgramVersion $version): array
{
    return $version->prescriptions()->get()
        ->map(fn (Prescription $p): array => [
            'exercise_id' => $p->exercise_id,
            ...$p->only(Prescription::DOSAGE_COLUMNS),
        ])
        ->all();
}

function logDone(User $user, ProgramVersion $version, Prescription $prescription, CarbonImmutable $date): void
{
    $session = WorkoutSession::create([
        'user_id' => $user->id,
        'program_version_id' => $version->id,
        'planned_date' => $date,
        'status' => 'done',
    ]);
    WorkoutSessionItem::create([
        'workout_session_id' => $session->id,
        'prescription_id' => $prescription->id,
        'sort_order' => 0,
        'status' => 'done',
    ]);
}

test('a daily exercise done this morning stays done through a same-day revision', function () {
    $today = CarbonImmutable::today();
    $v1 = $this->program->currentVersion;
    $daily = $v1->prescriptions()->where('frequency_kind', 'daily')->first();

    logDone($this->patient, $v1, $daily, $today);
    expect($this->schedule->dueOn($this->patient, $today)
        ->contains(fn ($item) => $item->prescription->exercise_id === $daily->exercise_id))->toBeFalse();

    $this->revisions->revise($this->program, 1, 'afternoon tweak', copyRows($v1));

    $due = $this->schedule->dueOn($this->patient, $today);
    expect($due->contains(fn ($item) => $item->prescription->exercise_id === $daily->exercise_id))->toBeFalse();

    // starting a session after the revision must not re-materialize the done exercise
    $this->actingAs($this->patient)->post('/sessions');
    $newSession = WorkoutSession::where('user_id', $this->patient->id)
        ->whereDate('planned_date', $today->toDateString())
        ->orderByDesc('id')->first();
    expect(
        WorkoutSessionItem::where('workout_session_id', $newSession->id)
            ->whereHas('prescription', fn ($q) => $q->where('exercise_id', $daily->exercise_id))
            ->exists()
    )->toBeFalse();
});

test('per_week days earned under the old version survive a mid-week revision', function () {
    $today = CarbonImmutable::today();
    $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
    $v1 = $this->program->currentVersion;
    $weekly = $v1->prescriptions()->where('frequency_kind', 'per_week')
        ->where('times_per_week', '>=', 3)->firstOrFail();

    logDone($this->patient, $v1, $weekly, $weekStart);
    logDone($this->patient, $v1, $weekly, $weekStart->addDay());

    $this->revisions->revise($this->program, 1, 'mid-week revision', copyRows($v1));

    $due = $this->schedule->dueOn($this->patient, $today);
    $item = $due->first(fn ($i) => $i->prescription->exercise_id === $weekly->exercise_id);

    if ($today->isSameDay($weekStart) || $today->isSameDay($weekStart->addDay())) {
        // done "today" ⇒ hidden regardless of remaining count
        expect($item)->toBeNull();
    } else {
        expect($item)->not->toBeNull()
            ->and($item->remainingThisWeek)->toBe((int) $weekly->times_per_week - 2);
    }
});

test('the same exercise done under two versions on one day counts one day, not two', function () {
    $today = CarbonImmutable::today();
    $v1 = $this->program->currentVersion;
    $weekly = $v1->prescriptions()->where('frequency_kind', 'per_week')
        ->where('times_per_week', '>=', 2)->firstOrFail();

    logDone($this->patient, $v1, $weekly, $today);
    $v2 = $this->revisions->revise($this->program, 1, null, copyRows($v1));
    $v2Weekly = $v2->prescriptions->firstWhere('exercise_id', $weekly->exercise_id);
    logDone($this->patient, $v2, $v2Weekly, $today);

    // tomorrow's view of this week: one day earned, not two
    $tomorrow = $today->addDay();
    if ($tomorrow->startOfWeek(CarbonImmutable::MONDAY)->isSameDay($today->startOfWeek(CarbonImmutable::MONDAY))) {
        $item = $this->schedule->dueOn($this->patient, $tomorrow)
            ->first(fn ($i) => $i->prescription->exercise_id === $weekly->exercise_id);
        expect($item)->not->toBeNull()
            ->and($item->remainingThisWeek)->toBe((int) $weekly->times_per_week - 1);
    } else {
        expect(true)->toBeTrue(); // Sunday edge: tomorrow is a fresh week, dedupe unobservable
    }
});

test('dashboard weekly ring survives a revision', function () {
    $today = CarbonImmutable::today();
    $v1 = $this->program->currentVersion;
    $weekly = $v1->prescriptions()->where('frequency_kind', 'per_week')->firstOrFail();

    logDone($this->patient, $v1, $weekly, $today);

    $before = $this->actingAs($this->patient)->get('/dashboard')
        ->viewData('page')['props']['weekly']['done'];

    $this->revisions->revise($this->program, 1, null, copyRows($v1));

    $after = $this->actingAs($this->patient)->get('/dashboard')
        ->viewData('page')['props']['weekly']['done'];

    expect($before)->toBe(1)->and($after)->toBe(1);
});

test('due item thumbs come from exercise_media, null when the exercise has no image', function () {
    $today = CarbonImmutable::today();
    $v1 = $this->program->currentVersion;

    $seeded = $this->schedule->dueOn($this->patient, $today)->first();
    $slug = $seeded->prescription->exercise->slug;
    expect($seeded->toArray()['thumb'])->toBe("/thumbs/{$slug}.svg");

    $bare = Exercise::create([
        'slug' => 'no-image-move',
        'canonical_name' => 'No Image Move',
        'steps' => ['just do it'],
        'equipment' => [],
    ]);
    $rows = copyRows($v1);
    $rows[] = ['exercise_id' => $bare->id, 'frequency_kind' => 'daily', 'sets' => 1, 'sort_order' => 99];
    $this->revisions->revise($this->program, 1, null, $rows);

    $item = $this->schedule->dueOn($this->patient, $today)
        ->first(fn ($i) => $i->prescription->exercise_id === $bare->id);
    expect($item->toArray()['thumb'])->toBeNull();
});

test('today page renders without lazy loading', function () {
    // Model::preventLazyLoading is active outside production — an N+1 here throws
    $this->actingAs($this->patient)->get('/today')->assertOk();
});
