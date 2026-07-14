<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\StaleRevisionException;
use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Prescription;
use App\Models\Program;
use App\Services\ProgramRevisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dosage editing goes through the revision engine — one save = one new
 * program_version with copied-then-modified rows (design rule 1). The grid
 * posts base_version_no; a stale grid gets a validation error, never a
 * silent revert (CONTENT-ADMIN.md 3A) and never a raw 409 modal (OV-A).
 */
class ProgramController extends Controller
{
    public function edit(): Response
    {
        // Single-program app: the seeded program is the only one. Multi-program
        // admin is explicitly out of scope (CONTENT-ADMIN.md NOT in scope).
        $program = Program::query()->firstOrFail();
        $version = $program->currentVersion()->with('prescriptions.exercise')->firstOrFail();

        return Inertia::render('admin/Program', [
            'program_title' => $program->title,
            'base_version_no' => $version->version_no,
            'effective_from' => $version->effective_from->toDateString(),
            'source_note' => $version->source_note,
            'rows' => $version->prescriptions->map(fn (Prescription $p): array => [
                'exercise_id' => $p->exercise_id,
                'exercise_name' => $p->exercise->canonical_name,
                'exercise_slug' => $p->exercise->slug,
                ...$p->only(Prescription::DOSAGE_COLUMNS),
            ])->values(),
            'exercises' => Exercise::query()->orderBy('canonical_name')
                ->get(['id', 'canonical_name'])
                ->map(fn (Exercise $e): array => ['id' => $e->id, 'name' => $e->canonical_name]),
        ]);
    }

    public function update(Request $request, ProgramRevisionService $revisions): RedirectResponse
    {
        $validated = $request->validate([
            'base_version_no' => ['required', 'integer', 'min:1'],
            'source_note' => ['nullable', 'string', 'max:500'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.exercise_id' => ['required', 'integer', 'exists:exercises,id'],
            'rows.*.sets' => ['nullable', 'integer', 'min:1', 'max:100'],
            'rows.*.reps' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rows.*.hold_sec' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'rows.*.eccentric_sec' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'rows.*.rest_sec' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'rows.*.per_side' => ['boolean'],
            'rows.*.to_fatigue' => ['boolean'],
            'rows.*.duration_sec' => ['nullable', 'integer', 'min:1', 'max:7200'],
            'rows.*.frequency_kind' => ['required', Rule::in(['daily', 'per_week', 'as_needed'])],
            'rows.*.times_per_week' => ['nullable', 'integer', 'min:1', 'max:7', 'required_if:rows.*.frequency_kind,per_week'],
            'rows.*.time_of_day' => ['nullable', Rule::in(['morning', 'evening', 'any'])],
            'rows.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $program = Program::query()->firstOrFail();

        try {
            $version = $revisions->revise(
                $program,
                (int) $validated['base_version_no'],
                $validated['source_note'] ?? null,
                $validated['rows'],
            );
        } catch (StaleRevisionException $e) {
            throw ValidationException::withMessages(['base_version_no' => $e->getMessage()]);
        }

        return back()->with('success', "Revision v{$version->version_no} is live (effective today).");
    }
}
