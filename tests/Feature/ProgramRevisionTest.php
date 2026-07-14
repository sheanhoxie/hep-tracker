<?php

use App\Exceptions\StaleRevisionException;
use App\Models\Exercise;
use App\Models\Prescription;
use App\Models\ProgramVersion;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\ProgramRevisionService;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Database\Seeders\HepProgramSeeder;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->patient = User::where('email', 'patient@example.com')->firstOrFail();
    $this->program = $this->patient->programs()->firstOrFail();
    $this->service = app(ProgramRevisionService::class);
});

/** @return list<array<string, mixed>> */
function rowsFrom(ProgramVersion $version): array
{
    return $version->prescriptions
        ->map(fn (Prescription $p): array => [
            'exercise_id' => $p->exercise_id,
            ...$p->only(Prescription::DOSAGE_COLUMNS),
        ])
        ->all();
}

test('revise copies every dosage column verbatim into a new version', function () {
    $v1 = $this->program->currentVersion;
    $v1->load('prescriptions');

    $v2 = $this->service->revise($this->program, 1, 'copy test', rowsFrom($v1));

    expect($v2->version_no)->toBe(2)
        ->and($v2->prescriptions)->toHaveCount(22);

    $byExercise = $v2->prescriptions->keyBy('exercise_id');
    foreach ($v1->prescriptions as $old) {
        $new = $byExercise[$old->exercise_id];
        expect($new->only(Prescription::DOSAGE_COLUMNS))
            ->toEqual($old->only(Prescription::DOSAGE_COLUMNS));
        expect($new->id)->not->toBe($old->id);
    }
});

test('revise closes the old version and leaves exactly one open version', function () {
    $v1 = $this->program->currentVersion;

    $this->service->revise($this->program, 1, null, rowsFrom($v1->load('prescriptions')));

    $today = CarbonImmutable::today();
    expect($v1->fresh()->effective_to->toDateString())->toBe($today->subDay()->toDateString())
        ->and($this->program->versions()->whereNull('effective_to')->count())->toBe(1)
        ->and($this->program->currentVersion()->first()->version_no)->toBe(2);
});

test('old prescription rows are untouched by a revision', function () {
    $v1 = $this->program->currentVersion;
    $before = $v1->prescriptions()->orderBy('id')->get()->map->getAttributes()->all();

    $rows = rowsFrom($v1->load('prescriptions'));
    $rows[0]['reps'] = 99; // modify a copy — original must not change
    $this->service->revise($this->program, 1, null, $rows);

    $after = $v1->prescriptions()->orderBy('id')->get()->map->getAttributes()->all();
    expect($after)->toEqual($before);
});

test('edit, add and remove land in the new version', function () {
    $v1 = $this->program->currentVersion->load('prescriptions');
    $rows = rowsFrom($v1);

    $rows[0]['reps'] = 42;                       // edit
    $removedExercise = $rows[1]['exercise_id'];  // remove
    unset($rows[1]);
    $rows = array_values($rows);
    $newExercise = Exercise::create([            // add
        'slug' => 'test-new-move',
        'canonical_name' => 'Test New Move',
        'steps' => ['do it'],
        'equipment' => [],
    ]);
    $rows[] = [
        'exercise_id' => $newExercise->id,
        'frequency_kind' => 'daily',
        'sets' => 2, 'reps' => 10,
        'sort_order' => 99,
    ];

    $v2 = $this->service->revise($this->program, 1, 'edit/add/remove', $rows);

    expect($v2->prescriptions)->toHaveCount(22) // 22 - 1 + 1
        ->and($v2->prescriptions->firstWhere('exercise_id', $rows[0]['exercise_id'])->reps)->toBe(42)
        ->and($v2->prescriptions->firstWhere('exercise_id', $removedExercise))->toBeNull()
        ->and($v2->prescriptions->firstWhere('exercise_id', $newExercise->id)->frequency_kind)->toBe('daily');
});

test('a stale base version number is rejected and writes nothing', function () {
    $v1 = $this->program->currentVersion->load('prescriptions');
    $rows = rowsFrom($v1);

    $this->service->revise($this->program, 1, null, $rows); // now at v2

    expect(fn () => $this->service->revise($this->program, 1, null, $rows))
        ->toThrow(StaleRevisionException::class);

    expect($this->program->versions()->count())->toBe(2)
        ->and($this->program->currentVersion()->first()->version_no)->toBe(2)
        ->and(Prescription::count())->toBe(44);
});

test('same-day re-revision empty-windows the middle version and scheduling resolves the newest', function () {
    $v1 = $this->program->currentVersion->load('prescriptions');
    $rows = rowsFrom($v1);

    $v2 = $this->service->revise($this->program, 1, 'first save', $rows);
    $v3 = $this->service->revise($this->program, 2, 'typo fix', $rows);

    $today = CarbonImmutable::today();
    $v2 = $v2->fresh();
    expect($v2->effective_from->toDateString())->toBe($today->toDateString())
        ->and($v2->effective_to->toDateString())->toBe($today->subDay()->toDateString()) // empty window
        ->and($v3->effective_to)->toBeNull();

    $resolved = app(ScheduleService::class)->currentVersion($this->patient, $today);
    expect($resolved->id)->toBe($v3->id);
});

test('existing sessions keep pointing at the superseded version', function () {
    $v1 = $this->program->currentVersion->load('prescriptions');
    $session = WorkoutSession::create([
        'user_id' => $this->patient->id,
        'program_version_id' => $v1->id,
        'planned_date' => today(),
        'status' => 'done',
    ]);

    $this->service->revise($this->program, 1, null, rowsFrom($v1));

    expect($session->fresh()->program_version_id)->toBe($v1->id);
});

test('revising a program with no open version refuses loudly', function () {
    $this->program->versions()->update(['effective_to' => today()->subDay()]);

    expect(fn () => $this->service->revise($this->program, 1, null, []))
        ->toThrow(RuntimeException::class, 'invariant breach');
});
