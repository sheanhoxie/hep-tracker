<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExerciseController extends Controller
{
    /**
     * One rules array for store and update (CONTENT-ADMIN.md 7A): the rule set
     * is identical — slug is generated server-side and immutable after create.
     * body_region/movement_pattern are free text (6A): prod data holds 17
     * distinct patterns; the 5-value schema comment is not a real taxonomy.
     */
    private function rules(): array
    {
        return [
            'canonical_name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'steps' => ['array'],
            'steps.*' => ['string', 'max:500'],
            'body_region' => ['nullable', 'string', 'max:100'],
            'movement_pattern' => ['nullable', 'string', 'max:100'],
            'laterality' => ['required', Rule::in(['bilateral', 'unilateral', 'alternating'])],
            'equipment' => ['array'],
            'equipment.*' => ['string', 'max:100'],
            'cues' => ['array'],
            'cues.*' => ['string', 'max:300'],
        ];
    }

    public function index(): Response
    {
        $exercises = Exercise::query()
            ->with('media')
            ->withCount('prescriptions')
            ->orderBy('canonical_name')
            ->get()
            ->map(fn (Exercise $exercise): array => [
                'slug' => $exercise->slug,
                'name' => $exercise->canonical_name,
                'region' => $exercise->body_region,
                'pattern' => $exercise->movement_pattern,
                'laterality' => $exercise->laterality,
                'thumb' => $exercise->media->firstWhere('kind', 'image')?->url,
                'has_video' => $exercise->media->firstWhere('kind', 'video') !== null,
                'prescribed' => $exercise->prescriptions_count > 0,
            ]);

        return Inertia::render('admin/exercises/Index', ['exercises' => $exercises]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/exercises/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $exercise = Exercise::create([
            ...$this->exerciseAttributes($validated),
            'slug' => $this->uniqueSlug($validated['canonical_name']),
            'created_by' => $request->user()->id,
        ]);
        $this->syncStockCues($exercise, $validated['cues'] ?? []);

        Log::info('content.edit', [
            'action' => 'exercise.create',
            'actor_id' => $request->user()->id,
            'exercise_slug' => $exercise->slug,
        ]);

        return redirect()
            ->route('admin.exercises.edit', $exercise)
            ->with('success', "\"{$exercise->canonical_name}\" created — now add media and prescribe it on the Program page.");
    }

    public function edit(Exercise $exercise): Response
    {
        $exercise->load(['media', 'cues' => fn ($q) => $q->whereNull('user_id')]);

        return Inertia::render('admin/exercises/Edit', [
            'exercise' => [
                'slug' => $exercise->slug,
                'canonical_name' => $exercise->canonical_name,
                'description' => $exercise->description,
                'steps' => $exercise->steps,
                'body_region' => $exercise->body_region,
                'movement_pattern' => $exercise->movement_pattern,
                'laterality' => $exercise->laterality,
                'equipment' => $exercise->equipment,
                'cues' => $exercise->cues->pluck('cue'),
                'media' => $exercise->media->map(fn ($m): array => [
                    'id' => $m->id,
                    'kind' => $m->kind,
                    'url' => $m->url,
                ])->values(),
                'prescribed' => $exercise->prescriptions()->exists(),
            ],
        ]);
    }

    public function update(Request $request, Exercise $exercise): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        // Laterality freeze (CONTENT-ADMIN.md L-A): expandSetQueue reads it at
        // session materialization — changing it while prescribed in the open
        // version would be an unversioned dosage change.
        if ($validated['laterality'] !== $exercise->laterality && $this->prescribedInOpenVersion($exercise)) {
            throw ValidationException::withMessages([
                'laterality' => 'laterality is frozen while this exercise is prescribed — revise the program (remove it, change, re-add) instead',
            ]);
        }

        $changed = array_keys(array_diff_assoc(
            array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $this->exerciseAttributes($validated)),
            array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $exercise->only(array_keys($this->exerciseAttributes($validated)))),
        ));

        $exercise->update($this->exerciseAttributes($validated));
        $this->syncStockCues($exercise, $validated['cues'] ?? []);

        Log::info('content.edit', [
            'action' => 'exercise.update',
            'actor_id' => $request->user()->id,
            'exercise_slug' => $exercise->slug,
            'changed_fields' => $changed,
        ]);

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, Exercise $exercise): RedirectResponse
    {
        if ($exercise->prescriptions()->exists()) {
            throw ValidationException::withMessages([
                'exercise' => "\"{$exercise->canonical_name}\" is prescribed in a program version and can't be deleted — remove it via a program revision first",
            ]);
        }

        foreach ($exercise->media()->get() as $media) {
            if (str_starts_with($media->url, '/storage/')) {
                Storage::disk('public')->delete(Str::after($media->url, '/storage/'));
            }
        }
        $slug = $exercise->slug;
        $exercise->delete(); // cascades media/cues/aliases rows

        Log::info('content.edit', [
            'action' => 'exercise.delete',
            'actor_id' => $request->user()->id,
            'exercise_slug' => $slug,
        ]);

        return redirect()->route('admin.exercises.index')->with('success', 'Exercise deleted.');
    }

    /** @return array<string, mixed> */
    private function exerciseAttributes(array $validated): array
    {
        return [
            'canonical_name' => $validated['canonical_name'],
            'description' => $validated['description'] ?? null,
            'steps' => array_values($validated['steps'] ?? []),
            'body_region' => $validated['body_region'] ?? null,
            'movement_pattern' => $validated['movement_pattern'] ?? null,
            'laterality' => $validated['laterality'],
            'equipment' => array_values($validated['equipment'] ?? []),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;
        while (Exercise::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /** @param list<string> $cues */
    private function syncStockCues(Exercise $exercise, array $cues): void
    {
        // Stock cues only (user_id NULL) — patient-owned cues survive untouched.
        $exercise->cues()->whereNull('user_id')->delete();
        foreach (array_values($cues) as $i => $cue) {
            $exercise->cues()->create(['cue' => $cue, 'sort_order' => $i]);
        }
    }

    private function prescribedInOpenVersion(Exercise $exercise): bool
    {
        return $exercise->prescriptions()
            ->whereHas('programVersion', fn ($q) => $q->whereNull('effective_to'))
            ->exists();
    }
}
