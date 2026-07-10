<?php

use App\Models\ExerciseAlias;
use App\Models\Observation;
use App\Models\SetEvent;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionItem;
use Database\Seeders\HepProgramSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->patient = User::where('email', 'patient@example.com')->firstOrFail();
});

test('seeder builds the full entity chain', function () {
    $program = $this->patient->programs()->firstOrFail();
    $version = $program->currentVersion;

    expect($version->version_no)->toBe(1)
        ->and($version->prescriptions)->toHaveCount(22)
        ->and($version->prescriptions->every(fn ($p) => $p->exercise !== null))->toBeTrue()
        ->and($version->prescriptions->where('frequency_kind', 'daily'))->toHaveCount(8)
        ->and($version->prescriptions->where('frequency_kind', 'per_week'))->toHaveCount(5)
        ->and($version->prescriptions->where('frequency_kind', 'as_needed'))->toHaveCount(9);

    $singleLegSquat = $version->prescriptions
        ->first(fn ($p) => $p->exercise->slug === 'eccentric-single-leg-squat');
    expect($singleLegSquat->frequency_kind)->toBe('per_week')
        ->and($singleLegSquat->times_per_week)->toBe(3)
        ->and($singleLegSquat->eccentric_sec)->toBe(6)
        ->and($singleLegSquat->per_side)->toBeTrue();
});

test('aliases resolve to exercises', function () {
    $alias = ExerciseAlias::where('user_id', $this->patient->id)
        ->where('alias', 'sample alias')->firstOrFail();

    expect($alias->exercise->slug)->toBe('standing-hip-abduction')
        ->and($alias->is_display_name)->toBeTrue();
});

test('seeder is idempotent', function () {
    $this->seed(HepProgramSeeder::class);

    expect($this->patient->programs()->count())->toBe(1)
        ->and($this->patient->programs()->first()->versions()->first()->prescriptions()->count())->toBe(22);
});

test('a completed session surfaces in the weekly completions view', function () {
    $version = $this->patient->programs()->firstOrFail()->currentVersion;
    $prescription = $version->prescriptions->firstWhere('frequency_kind', 'per_week');

    $session = WorkoutSession::create([
        'user_id' => $this->patient->id,
        'program_version_id' => $version->id,
        'planned_date' => '2026-07-08',
        'status' => 'done',
    ]);

    $item = WorkoutSessionItem::create([
        'workout_session_id' => $session->id,
        'prescription_id' => $prescription->id,
        'sort_order' => 0,
        'status' => 'done',
    ]);

    SetEvent::create([
        'workout_session_item_id' => $item->id,
        'client_key' => (string) Str::uuid(),
        'set_no' => 1,
        'side' => 'right',
        'reps_done' => 8,
    ]);

    $row = DB::table('v_weekly_completions')
        ->where('prescription_id', $prescription->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->days_done)->toBe(1);
});

test('set_events dedupe on client_key', function () {
    $version = $this->patient->programs()->firstOrFail()->currentVersion;
    $session = WorkoutSession::create([
        'user_id' => $this->patient->id,
        'program_version_id' => $version->id,
        'planned_date' => '2026-07-08',
    ]);
    $item = WorkoutSessionItem::create([
        'workout_session_id' => $session->id,
        'prescription_id' => $version->prescriptions->first()->id,
        'sort_order' => 0,
    ]);

    $key = (string) Str::uuid();
    $attrs = ['workout_session_item_id' => $item->id, 'client_key' => $key, 'set_no' => 1];

    SetEvent::create($attrs);

    expect(fn () => SetEvent::create($attrs))->toThrow(QueryException::class);
});

test('observations require a session or item grain', function () {
    expect(fn () => Observation::create([
        'user_id' => $this->patient->id,
        'kind' => 'pain',
        'numeric_value' => 3,
    ]))->toThrow(QueryException::class);
});
