<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use App\Models\SetEvent;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionItem;
use App\Services\SessionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function store(Request $request, SessionService $sessions): RedirectResponse
    {
        $session = $sessions->startOrResume($request->user(), CarbonImmutable::today());

        if ($session === null) {
            return redirect()->route('today');
        }

        return redirect()->route('sessions.show', $session);
    }

    public function show(Request $request, WorkoutSession $workoutSession): Response
    {
        abort_unless($workoutSession->user_id === $request->user()->id, 404);

        $workoutSession->load(['items.prescription.exercise.cues', 'items.prescription.exercise.media', 'items.setEvents']);

        return Inertia::render('Session', [
            'session' => [
                'id' => $workoutSession->id,
                'status' => $workoutSession->status,
                'planned_date' => $workoutSession->planned_date->toDateString(),
                'started_at' => $workoutSession->started_at?->toIso8601String(),
            ],
            'items' => $workoutSession->items->map(fn (WorkoutSessionItem $item): array => [
                'id' => $item->id,
                'status' => $item->status,
                'skip_reason' => $item->skip_reason,
                'exercise' => [
                    'slug' => $item->prescription->exercise->slug,
                    'name' => $item->prescription->exercise->canonical_name,
                    'steps' => $item->prescription->exercise->steps,
                    'laterality' => $item->prescription->exercise->laterality,
                    'cues' => $item->prescription->exercise->cues->pluck('cue'),
                    'thumb' => $item->prescription->exercise->media->firstWhere('kind', 'image')?->url,
                    'video' => $item->prescription->exercise->media->firstWhere('kind', 'video')?->url,
                ],
                'dosage' => $item->prescription->dosageSummary(),
                'notes' => $item->prescription->notes,
                'set_queue' => SessionService::expandSetQueue($item->prescription),
                'set_events' => $item->setEvents->map(fn (SetEvent $event): array => [
                    'set_no' => $event->set_no,
                    'side' => $event->side,
                    'completed' => $event->completed,
                ]),
            ]),
            'observations' => $workoutSession->observations()
                ->whereNull('workout_session_item_id')
                ->get()
                ->map(fn (Observation $o): array => [
                    'kind' => $o->kind,
                    'value' => $o->numeric_value !== null ? (float) $o->numeric_value : null,
                    'text' => $o->text_value,
                ]),
        ]);
    }

    public function update(Request $request, WorkoutSession $workoutSession): RedirectResponse
    {
        abort_unless($workoutSession->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['done', 'abandoned'])],
        ]);

        $workoutSession->update([...$data, 'ended_at' => now()]);

        return back();
    }
}
