<?php

use App\Models\Prescription;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionItem;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Database\Seeders\HepProgramSeeder;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->patient = User::where('email', 'patient@example.com')->firstOrFail();
    $this->version = $this->patient->programs()->firstOrFail()->currentVersion;
    $this->schedule = new ScheduleService();
    // A Wednesday, so the ISO week has room on both sides.
    $this->date = CarbonImmutable::parse('2026-07-08');
});

function completeOn(User $user, Prescription $prescription, string $date): void
{
    $session = WorkoutSession::create([
        'user_id' => $user->id,
        'program_version_id' => $prescription->program_version_id,
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

test('everything scheduled is due on a fresh week', function () {
    $due = $this->schedule->dueOn($this->patient, $this->date);

    expect($due)->toHaveCount(13)
        ->and($due->filter(fn ($d) => $d->cadence === 'daily'))->toHaveCount(8)
        ->and($due->filter(fn ($d) => $d->cadence === 'weekly'))->toHaveCount(5)
        ->and($due->every(fn ($d) => $d->prescription->frequency_kind !== 'as_needed'))->toBeTrue();
});

test('a daily item done today is no longer due', function () {
    $daily = $this->version->prescriptions->firstWhere('frequency_kind', 'daily');
    completeOn($this->patient, $daily, $this->date->toDateString());

    $due = $this->schedule->dueOn($this->patient, $this->date);

    expect($due->pluck('prescription.id'))->not->toContain($daily->id)
        ->and($due)->toHaveCount(12);
});

test('a daily item done yesterday is due again today', function () {
    $daily = $this->version->prescriptions->firstWhere('frequency_kind', 'daily');
    completeOn($this->patient, $daily, $this->date->subDay()->toDateString());

    $due = $this->schedule->dueOn($this->patient, $this->date);

    expect($due->pluck('prescription.id'))->toContain($daily->id);
});

test('a weekly item counts down and drops off at times_per_week', function () {
    $weekly = $this->version->prescriptions->firstWhere('frequency_kind', 'per_week');
    $monday = $this->date->startOfWeek(CarbonImmutable::MONDAY);

    completeOn($this->patient, $weekly, $monday->toDateString());
    $due = $this->schedule->dueOn($this->patient, $this->date);
    $item = $due->first(fn ($d) => $d->prescription->id === $weekly->id);
    expect($item->remainingThisWeek)->toBe(2);

    completeOn($this->patient, $weekly, $monday->addDay()->toDateString());
    completeOn($this->patient, $weekly, $monday->addDays(4)->toDateString());
    $due = $this->schedule->dueOn($this->patient, $this->date);
    expect($due->pluck('prescription.id'))->not->toContain($weekly->id);
});

test('a weekly item done earlier today is not due even with remaining sessions', function () {
    $weekly = $this->version->prescriptions->firstWhere('frequency_kind', 'per_week');
    completeOn($this->patient, $weekly, $this->date->toDateString());

    $due = $this->schedule->dueOn($this->patient, $this->date);

    expect($due->pluck('prescription.id'))->not->toContain($weekly->id);
});

test('weekly completions reset at the ISO Monday boundary', function () {
    $weekly = $this->version->prescriptions->firstWhere('frequency_kind', 'per_week');
    $lastWeekMonday = $this->date->subWeek()->startOfWeek(CarbonImmutable::MONDAY);

    completeOn($this->patient, $weekly, $lastWeekMonday->toDateString());
    completeOn($this->patient, $weekly, $lastWeekMonday->addDays(2)->toDateString());
    completeOn($this->patient, $weekly, $lastWeekMonday->addDays(4)->toDateString());

    $due = $this->schedule->dueOn($this->patient, $this->date);
    $item = $due->first(fn ($d) => $d->prescription->id === $weekly->id);

    expect($item)->not->toBeNull()
        ->and($item->remainingThisWeek)->toBe(3);
});

test('nothing is due before the program takes effect', function () {
    $due = $this->schedule->dueOn($this->patient, CarbonImmutable::parse('2026-06-01'));

    expect($due)->toBeEmpty();
});

test('the today page renders the due list', function () {
    $response = $this->actingAs($this->patient)->get('/today');

    $response->assertOk()->assertInertia(
        fn ($page) => $page->component('Today')->has('due', 13),
    );
});
