<?php

use App\Models\Exercise;
use App\Models\ExerciseCue;
use App\Models\ExerciseMedia;
use App\Models\User;
use Database\Seeders\HepProgramSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(HepProgramSeeder::class);
    $this->patient = User::where('email', 'patient@example.com')->firstOrFail();
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->practitioner = User::factory()->create(['role' => 'practitioner']);
});

// ── Authz ────────────────────────────────────────────────────────────────

test('guests are redirected to login from every admin route', function (string $method, string $uri) {
    $this->{$method}($uri)->assertRedirect('/login');
})->with([
    ['get', '/admin/exercises'],
    ['get', '/admin/exercises/create'],
    ['post', '/admin/exercises'],
    ['get', '/admin/program'],
    ['put', '/admin/program'],
]);

test('patients get 403 on every admin route', function (string $method, string $uri) {
    $this->actingAs($this->patient)->{$method}($uri)->assertForbidden();
})->with([
    ['get', '/admin/exercises'],
    ['get', '/admin/exercises/create'],
    ['post', '/admin/exercises'],
    ['get', '/admin/program'],
    ['put', '/admin/program'],
]);

test('practitioner and admin can reach the admin pages', function () {
    $this->actingAs($this->practitioner)->get('/admin/exercises')->assertOk();
    $this->actingAs($this->admin)->get('/admin/exercises')->assertOk();
    $this->actingAs($this->admin)->get('/admin/program')->assertOk();
});

test('user:role promotes and demotes, rejects unknowns', function () {
    $this->artisan('user:role', ['email' => $this->patient->email, 'role' => 'practitioner'])
        ->assertSuccessful();
    expect($this->patient->fresh()->role)->toBe('practitioner');

    $this->artisan('user:role', ['email' => $this->patient->email, 'role' => 'patient'])
        ->assertSuccessful();
    expect($this->patient->fresh()->role)->toBe('patient');

    $this->artisan('user:role', ['email' => $this->patient->email, 'role' => 'superuser'])->assertFailed();
    $this->artisan('user:role', ['email' => 'nobody@example.com', 'role' => 'admin'])->assertFailed();
});

// ── Exercise CRUD ────────────────────────────────────────────────────────

test('store creates an exercise with generated slug and stock cues', function () {
    $this->actingAs($this->admin)->post('/admin/exercises', [
        'canonical_name' => 'Calf Stretch on Step',
        'steps' => ['stand on step', 'drop heel slowly'],
        'laterality' => 'unilateral',
        'equipment' => ['step'],
        'cues' => ['keep knee straight', 'no bouncing'],
    ])->assertRedirect('/admin/exercises/calf-stretch-on-step/edit');

    $exercise = Exercise::where('slug', 'calf-stretch-on-step')->firstOrFail();
    expect($exercise->steps)->toBe(['stand on step', 'drop heel slowly'])
        ->and($exercise->created_by)->toBe($this->admin->id)
        ->and($exercise->cues()->whereNull('user_id')->orderBy('sort_order')->pluck('cue')->all())
        ->toBe(['keep knee straight', 'no bouncing']);
});

test('slug collisions get numeric suffixes', function () {
    $payload = ['canonical_name' => 'Bridge Lift', 'laterality' => 'bilateral', 'steps' => [], 'equipment' => []];

    $this->actingAs($this->admin)->post('/admin/exercises', $payload)
        ->assertRedirect('/admin/exercises/bridge-lift-2/edit'); // seeder owns bridge-lift
    $this->actingAs($this->admin)->post('/admin/exercises', $payload)
        ->assertRedirect('/admin/exercises/bridge-lift-3/edit');
});

test('update keeps the slug when the name changes', function () {
    $exercise = Exercise::where('slug', 'bridge-lift')->firstOrFail();

    $this->actingAs($this->admin)->patch('/admin/exercises/bridge-lift', [
        'canonical_name' => 'Glute Bridge (renamed)',
        'steps' => $exercise->steps,
        'laterality' => $exercise->laterality,
        'equipment' => $exercise->equipment,
        'cues' => [],
    ])->assertRedirect();

    expect($exercise->fresh()->slug)->toBe('bridge-lift')
        ->and($exercise->fresh()->canonical_name)->toBe('Glute Bridge (renamed)');
});

test('free-text pattern and region are accepted, bad laterality is not', function () {
    $exercise = Exercise::where('slug', 'bridge-lift')->firstOrFail();
    $base = [
        'canonical_name' => $exercise->canonical_name,
        'steps' => $exercise->steps,
        'equipment' => $exercise->equipment,
        'cues' => [],
    ];

    $this->actingAs($this->admin)->patch('/admin/exercises/bridge-lift', [
        ...$base,
        'laterality' => $exercise->laterality,
        'movement_pattern' => 'hinge/bridge', // real seeded value, not in the 5-value comment
        'body_region' => 'hip',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->patch('/admin/exercises/bridge-lift', [
        ...$base,
        'laterality' => 'both-sides', // not a real value
    ])->assertInvalid(['laterality']);
});

test('laterality is frozen while prescribed in the open version', function () {
    $exercise = Exercise::where('slug', 'bridge-lift')->firstOrFail(); // seeded → prescribed in v1

    $this->actingAs($this->admin)->patch('/admin/exercises/bridge-lift', [
        'canonical_name' => $exercise->canonical_name,
        'steps' => $exercise->steps,
        'equipment' => $exercise->equipment,
        'cues' => [],
        'laterality' => 'alternating',
    ])->assertInvalid(['laterality']);

    expect($exercise->fresh()->laterality)->not->toBe('alternating');
});

test('cue sync leaves patient-owned cues untouched', function () {
    $exercise = Exercise::where('slug', 'bridge-lift')->firstOrFail();
    $patientCue = ExerciseCue::create([
        'exercise_id' => $exercise->id,
        'user_id' => $this->patient->id,
        'cue' => 'my personal reminder',
        'sort_order' => 0,
    ]);

    $this->actingAs($this->admin)->patch('/admin/exercises/bridge-lift', [
        'canonical_name' => $exercise->canonical_name,
        'steps' => $exercise->steps,
        'laterality' => $exercise->laterality,
        'equipment' => $exercise->equipment,
        'cues' => ['brand new stock cue'],
    ])->assertRedirect();

    expect(ExerciseCue::find($patientCue->id))->not->toBeNull()
        ->and($exercise->cues()->whereNull('user_id')->pluck('cue')->all())->toBe(['brand new stock cue']);
});

test('a prescribed exercise cannot be deleted', function () {
    $this->actingAs($this->admin)->delete('/admin/exercises/bridge-lift')->assertInvalid(['exercise']);
    expect(Exercise::where('slug', 'bridge-lift')->exists())->toBeTrue();
});

test('an unprescribed exercise deletes with its cues, media and local files', function () {
    Storage::fake('public');
    $this->actingAs($this->admin)->post('/admin/exercises', [
        'canonical_name' => 'Doomed Move',
        'laterality' => 'bilateral',
        'steps' => [], 'equipment' => [],
        'cues' => ['gone soon'],
    ]);
    $exercise = Exercise::where('slug', 'doomed-move')->firstOrFail();
    $this->actingAs($this->admin)->post('/admin/exercises/doomed-move/media', [
        'kind' => 'video',
        'file' => UploadedFile::fake()->create('demo.mp4', 1024, 'video/mp4'),
    ])->assertSessionHasNoErrors();
    $path = Str::after($exercise->media()->firstOrFail()->url, '/storage/');
    Storage::disk('public')->assertExists($path);

    $this->actingAs($this->admin)->delete('/admin/exercises/doomed-move')->assertRedirect('/admin/exercises');

    expect(Exercise::where('slug', 'doomed-move')->exists())->toBeFalse()
        ->and(ExerciseMedia::where('exercise_id', $exercise->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($path);
});

// ── Media endpoint ───────────────────────────────────────────────────────

test('video upload replaces the placeholder row without duplicating', function () {
    Storage::fake('public');
    $exercise = Exercise::where('slug', 'bridge-lift')->firstOrFail();
    $before = $exercise->media()->where('kind', 'video')->count();

    $this->actingAs($this->admin)->post('/admin/exercises/bridge-lift/media', [
        'kind' => 'video',
        'file' => UploadedFile::fake()->create('real-form.mp4', 2048, 'video/mp4'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $rows = $exercise->media()->where('kind', 'video')->get();
    expect($before)->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->url)->toStartWith('/storage/exercise-media/bridge-lift/')
        ->and($rows->first()->uploaded_by)->toBe($this->admin->id)
        ->and($rows->first()->updated_at)->not->toBeNull();
});

test('re-upload deletes the old local file; url-paste over local also deletes it', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)->post('/admin/exercises/bridge-lift/media', [
        'kind' => 'video',
        'file' => UploadedFile::fake()->create('v1.mp4', 1024, 'video/mp4'),
    ]);
    $exercise = Exercise::where('slug', 'bridge-lift')->firstOrFail();
    $firstPath = Str::after($exercise->media()->where('kind', 'video')->firstOrFail()->url, '/storage/');

    $this->actingAs($this->admin)->post('/admin/exercises/bridge-lift/media', [
        'kind' => 'video',
        'file' => UploadedFile::fake()->create('v2.mp4', 1024, 'video/mp4'),
    ]);
    Storage::disk('public')->assertMissing($firstPath);
    $secondPath = Str::after($exercise->media()->where('kind', 'video')->firstOrFail()->url, '/storage/');

    $this->actingAs($this->admin)->post('/admin/exercises/bridge-lift/media', [
        'kind' => 'video',
        'url' => 'https://videos.example.com/bridge.mp4',
    ]);
    Storage::disk('public')->assertMissing($secondPath);
    expect($exercise->media()->where('kind', 'video')->firstOrFail()->url)
        ->toBe('https://videos.example.com/bridge.mp4');
});

test('url XOR file: both rejected, neither rejected', function () {
    $this->actingAs($this->admin)->post('/admin/exercises/bridge-lift/media', [
        'kind' => 'video',
        'file' => UploadedFile::fake()->create('x.mp4', 100, 'video/mp4'),
        'url' => 'https://example.com/x.mp4',
    ])->assertInvalid(['file']);

    $this->actingAs($this->admin)->post('/admin/exercises/bridge-lift/media', [
        'kind' => 'video',
    ])->assertInvalid(['file', 'url']);
});

test('oversized and wrong-mime uploads are rejected', function () {
    $this->actingAs($this->admin)->post('/admin/exercises/bridge-lift/media', [
        'kind' => 'video',
        'file' => UploadedFile::fake()->create('big.mp4', 103_000, 'video/mp4'), // > 100MB
    ])->assertInvalid(['file']);

    $this->actingAs($this->admin)->post('/admin/exercises/bridge-lift/media', [
        'kind' => 'video',
        'file' => UploadedFile::fake()->create('nope.pdf', 100, 'application/pdf'),
    ])->assertInvalid(['file']);
});

test('deleting an external-url media row attempts no local delete and removes the row', function () {
    Storage::fake('public');
    $exercise = Exercise::where('slug', 'bridge-lift')->firstOrFail();
    $media = $exercise->media()->where('kind', 'video')->firstOrFail(); // seeded external URL

    $this->actingAs($this->admin)
        ->delete("/admin/exercises/bridge-lift/media/{$media->id}")
        ->assertRedirect();

    expect(ExerciseMedia::find($media->id))->toBeNull();
});

test('media for another exercise is not reachable through scoped binding', function () {
    $other = Exercise::where('slug', 'toe-yoga')->firstOrFail();
    $foreign = $other->media()->firstOrFail();

    $this->actingAs($this->admin)
        ->delete("/admin/exercises/bridge-lift/media/{$foreign->id}")
        ->assertNotFound();
});
