<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    public function __invoke(Request $request, ScheduleService $schedule): Response
    {
        $user = $request->user();
        $version = $schedule->currentVersion($user, CarbonImmutable::today());
        $prescriptionsByExercise = $version?->prescriptions->keyBy('exercise_id') ?? collect();

        $exercises = Exercise::query()
            ->with([
                'aliases' => fn ($q) => $q->where('user_id', $user->id),
                'cues',
                'media',
            ])
            ->orderBy('canonical_name')
            ->get()
            ->map(function (Exercise $exercise) use ($prescriptionsByExercise): array {
                $prescription = $prescriptionsByExercise->get($exercise->id);

                return [
                    'slug' => $exercise->slug,
                    'name' => $exercise->canonical_name,
                    'region' => $exercise->body_region,
                    'laterality' => $exercise->laterality,
                    'steps' => $exercise->steps,
                    'cues' => $exercise->cues->whereNull('user_id')->pluck('cue')->values(),
                    'aliases' => $exercise->aliases->pluck('alias'),
                    'thumb' => $exercise->media->firstWhere('kind', 'image')?->url,
                    'video' => $exercise->media->firstWhere('kind', 'video')?->url,
                    'dosage' => $prescription?->dosageSummary(),
                    'frequency' => $prescription?->frequency_kind,
                    'notes' => $prescription?->notes,
                ];
            });

        return Inertia::render('Library', ['exercises' => $exercises]);
    }
}
