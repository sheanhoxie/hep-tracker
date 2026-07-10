<?php

namespace App\Http\Controllers;

use App\Models\WorkoutSession;
use App\Services\DueItem;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function __invoke(Request $request, ScheduleService $schedule): Response
    {
        $today = CarbonImmutable::today();

        return Inertia::render('Today', [
            'date' => $today->toDateString(),
            'due' => $schedule->dueOn($request->user(), $today)
                ->map(fn (DueItem $item): array => $item->toArray()),
            'active_session_id' => WorkoutSession::query()
                ->where('user_id', $request->user()->id)
                ->whereDate('planned_date', $today->toDateString())
                ->whereIn('status', ['planned', 'active'])
                ->value('id'),
        ]);
    }
}
