<?php

namespace App\Services;

use App\Models\Prescription;
use App\Models\ProgramVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure reads only: computes what's due from prescriptions + completion views,
 * never writes state (hep-data-model.md rule 2).
 *
 * Week boundary: ISO Monday — matches date_trunc('week') in v_weekly_completions
 * and the paper journal grid (spec §5). Decided 2026-07-08.
 *
 * Carry-over across revisions (CONTENT-ADMIN.md 1A): a revision mints new
 * prescription ids, so completions are matched on exercise_id, not
 * prescription_id — work done under a superseded version still counts.
 *
 *   v_daily_completions (user_id, planned_date, prescription_id, status, exercise_id)
 *        │ done rows, this user
 *        ├── today ──────────────► doneToday{exercise_id}          (daily dedupe)
 *        └── this ISO week ─────► COUNT(DISTINCT planned_date)
 *                                  GROUP BY exercise_id ──► daysDone{exercise_id}
 *                                  (distinct dates ⇒ same-day work under two
 *                                   versions counts once — the weekly grain
 *                                   is computed here, not from v_weekly_completions)
 */
final class ScheduleService
{
    /** @return Collection<int, DueItem> */
    public function dueOn(User $user, CarbonImmutable $date): Collection
    {
        $version = $this->currentVersion($user, $date);
        if ($version === null) {
            return new Collection();
        }

        $prescriptions = $version->prescriptions()->with('exercise.media')->get();

        $doneToday = DB::table('v_daily_completions')
            ->where('user_id', $user->id)
            ->whereDate('planned_date', $date->toDateString())
            ->where('status', 'done')
            ->pluck('exercise_id')
            ->flip();

        $weekStart = $date->startOfWeek(CarbonImmutable::MONDAY);
        $daysDoneThisWeek = DB::table('v_daily_completions')
            ->where('user_id', $user->id)
            ->where('status', 'done')
            ->whereDate('planned_date', '>=', $weekStart->toDateString())
            ->whereDate('planned_date', '<=', $weekStart->addDays(6)->toDateString())
            ->groupBy('exercise_id')
            ->selectRaw('exercise_id, COUNT(DISTINCT planned_date) AS days_done')
            ->pluck('days_done', 'exercise_id');

        return $prescriptions
            ->map(fn (Prescription $p): ?DueItem => match ($p->frequency_kind) {
                'daily' => $doneToday->has($p->exercise_id) ? null : new DueItem($p, 'daily', null),
                'per_week' => $this->weeklyDue($p, $doneToday->has($p->exercise_id), (int) ($daysDoneThisWeek[$p->exercise_id] ?? 0)),
                default => null, // as_needed: never scheduled
            })
            ->filter()
            ->values();
    }

    private function weeklyDue(Prescription $prescription, bool $doneToday, int $daysDone): ?DueItem
    {
        $remaining = max(0, (int) $prescription->times_per_week - $daysDone);

        if ($remaining === 0 || $doneToday) {
            return null;
        }

        return new DueItem($prescription, 'weekly', $remaining);
    }

    public function currentVersion(User $user, CarbonImmutable $date): ?ProgramVersion
    {
        return ProgramVersion::query()
            ->whereHas('program', fn (Builder $q) => $q->where('patient_id', $user->id))
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('version_no')
            ->first();
    }
}
