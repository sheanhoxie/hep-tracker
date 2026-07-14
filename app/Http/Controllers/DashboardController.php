<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use App\Models\WorkoutSession;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ScheduleService $schedule): Response
    {
        $user = $request->user();
        $today = CarbonImmutable::today();

        $due = $schedule->dueOn($user, $today);
        $version = $schedule->currentVersion($user, $today);

        $weekly = ['done' => 0, 'target' => 0];
        if ($version !== null) {
            $weeklyPrescriptions = $version->prescriptions()
                ->where('frequency_kind', 'per_week')->get();
            // exercise_id grain, computed from the daily view: earned days
            // survive a program revision (CONTENT-ADMIN.md 1A carry-over)
            $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
            $daysDone = DB::table('v_daily_completions')
                ->where('user_id', $user->id)
                ->where('status', 'done')
                ->whereDate('planned_date', '>=', $weekStart->toDateString())
                ->whereDate('planned_date', '<=', $weekStart->addDays(6)->toDateString())
                ->groupBy('exercise_id')
                ->selectRaw('exercise_id, COUNT(DISTINCT planned_date) AS days_done')
                ->pluck('days_done', 'exercise_id');
            $weekly = [
                'done' => $weeklyPrescriptions->sum(
                    fn ($p): int => min((int) ($daysDone[$p->exercise_id] ?? 0), (int) $p->times_per_week)
                ),
                'target' => (int) $weeklyPrescriptions->sum('times_per_week'),
            ];
        }

        $nudge = $user->reminders()->where('kind', 'daily_nudge')->first();

        return Inertia::render('Dashboard', [
            'due_count' => $due->count(),
            'reminder' => $nudge === null ? null : [
                'enabled' => $nudge->enabled,
                'time' => $nudge->schedule,
                // a dead iOS subscription is silent (DEPLOY.md F6) — a stale
                // "last sent" is the one user-visible signal something's wrong
                'last_sent_at' => $nudge->last_sent_at?->timezone(config('app.timezone'))->format('D M j, g:i A'),
            ],
            'vapid_public_key' => (string) config('webpush.vapid.public_key'),
            'weekly' => $weekly,
            'streak_days' => $this->streak($user->id, $today),
            'pain_series' => Observation::query()
                ->where('user_id', $user->id)
                ->where('kind', 'pain')
                ->whereNotNull('numeric_value')
                ->orderByDesc('occurred_at')
                ->limit(30)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (Observation $o): array => [
                    'date' => $o->occurred_at->toDateString(),
                    'value' => (float) $o->numeric_value,
                ]),
            'recent_sessions' => WorkoutSession::query()
                ->where('user_id', $user->id)
                ->withCount([
                    'items as items_total',
                    'items as items_done' => fn ($q) => $q->where('status', 'done'),
                ])
                ->orderByDesc('planned_date')->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (WorkoutSession $s): array => [
                    'id' => $s->id,
                    'date' => $s->planned_date->toDateString(),
                    'status' => $s->status,
                    'items_done' => $s->items_done,
                    'items_total' => $s->items_total,
                ]),
        ]);
    }

    /** Consecutive days ending today or yesterday with at least one completed item — computed, never stored. */
    private function streak(int $userId, CarbonImmutable $today): int
    {
        $days = DB::table('v_daily_completions')
            ->where('user_id', $userId)
            ->where('status', 'done')
            ->distinct()
            ->orderByDesc('planned_date')
            ->pluck('planned_date')
            ->map(fn (string $d): string => CarbonImmutable::parse($d)->toDateString());

        $streak = 0;
        $cursor = $days->first() === $today->toDateString() ? $today : $today->subDay();

        foreach ($days as $day) {
            if ($day !== $cursor->toDateString()) {
                break;
            }
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }
}
