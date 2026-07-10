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
            $daysDone = DB::table('v_weekly_completions')
                ->whereIn('prescription_id', $weeklyPrescriptions->pluck('id'))
                ->whereDate('week_start', $today->startOfWeek(CarbonImmutable::MONDAY)->toDateString())
                ->pluck('days_done', 'prescription_id');
            $weekly = [
                'done' => $weeklyPrescriptions->sum(
                    fn ($p): int => min((int) ($daysDone[$p->id] ?? 0), (int) $p->times_per_week)
                ),
                'target' => (int) $weeklyPrescriptions->sum('times_per_week'),
            ];
        }

        $nudge = $user->reminders()->where('kind', 'daily_nudge')->first();

        return Inertia::render('Dashboard', [
            'due_count' => $due->count(),
            'reminder' => $nudge === null ? null : ['enabled' => $nudge->enabled, 'time' => $nudge->schedule],
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
