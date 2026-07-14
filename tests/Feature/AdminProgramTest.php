<?php

use App\Models\Prescription;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\HepProgramSeeder;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->program = Program::firstOrFail();
});

/** @return array<string, mixed> */
function gridPayload(Program $program, int $base, ?callable $mutate = null): array
{
    $rows = $program->currentVersion()->firstOrFail()
        ->prescriptions()->get()
        ->map(fn (Prescription $p): array => [
            'exercise_id' => $p->exercise_id,
            ...$p->only(Prescription::DOSAGE_COLUMNS),
        ])->all();

    if ($mutate !== null) {
        $rows = $mutate($rows);
    }

    return ['base_version_no' => $base, 'source_note' => 'grid save', 'rows' => $rows];
}

test('the grid page serves current rows, base version and the exercise list', function () {
    $response = $this->actingAs($this->admin)->get('/admin/program')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['base_version_no'])->toBe(1)
        ->and($props['rows'])->toHaveCount(22)
        ->and($props['exercises'])->toHaveCount(22)
        ->and($props['rows'][0])->toHaveKeys(['exercise_id', 'exercise_name', ...Prescription::DOSAGE_COLUMNS]);
});

test('saving the grid creates a revision effective today', function () {
    $payload = gridPayload($this->program, 1, function (array $rows): array {
        $rows[0]['reps'] = 42;

        return $rows;
    });

    $this->actingAs($this->admin)->put('/admin/program', $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    $v2 = $this->program->currentVersion()->firstOrFail();
    expect($v2->version_no)->toBe(2)
        ->and($v2->effective_from->toDateString())->toBe(today()->toDateString())
        ->and($v2->prescriptions()->get()->firstWhere('exercise_id', $payload['rows'][0]['exercise_id'])->reps)->toBe(42);
});

test('a stale grid save returns a validation error, not a silent revert', function () {
    $this->actingAs($this->admin)->put('/admin/program', gridPayload($this->program, 1));

    // second tab still holds base_version_no = 1
    $this->actingAs($this->admin)->put('/admin/program', gridPayload($this->program, 1))
        ->assertInvalid(['base_version_no']);

    expect($this->program->versions()->count())->toBe(2);
});

test('per_week rows require times_per_week', function () {
    $payload = gridPayload($this->program, 1, function (array $rows): array {
        $rows[0]['frequency_kind'] = 'per_week';
        $rows[0]['times_per_week'] = null;

        return $rows;
    });

    $this->actingAs($this->admin)->put('/admin/program', $payload)
        ->assertInvalid(['rows.0.times_per_week']);
    expect($this->program->versions()->count())->toBe(1);
});
