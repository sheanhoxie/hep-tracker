<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use App\Models\WorkoutSession;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $sessions = WorkoutSession::query()
            ->where('user_id', $request->user()->id)
            ->withCount([
                'items as items_total',
                'items as items_done' => fn ($q) => $q->whereIn('status', ['done', 'skipped']),
            ])
            ->orderByDesc('planned_date')->orderByDesc('id')
            ->simplePaginate(20);

        $observations = Observation::query()
            ->whereIn('workout_session_id', $sessions->getCollection()->pluck('id'))
            ->whereNull('workout_session_item_id')
            ->get()
            ->groupBy('workout_session_id');

        return Inertia::render('History', [
            'sessions' => $sessions->getCollection()->map(function (WorkoutSession $session) use ($observations): array {
                /** @var Collection<int, Observation> $sessionObs */
                $sessionObs = $observations->get($session->id, collect());
                $pain = $sessionObs->firstWhere('kind', 'pain')?->numeric_value;
                $rpe = $sessionObs->firstWhere('kind', 'rpe')?->numeric_value;

                return [
                    'id' => $session->id,
                    'date' => $session->planned_date->toDateString(),
                    'status' => $session->status,
                    'items_done' => $session->items_done,
                    'items_total' => $session->items_total,
                    'pain' => $pain !== null ? (float) $pain : null,
                    'rpe' => $rpe !== null ? (float) $rpe : null,
                    'note' => $sessionObs->firstWhere('kind', 'note')?->text_value,
                ];
            }),
            'prev' => $sessions->previousPageUrl(),
            'next' => $sessions->nextPageUrl(),
        ]);
    }
}
