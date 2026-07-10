<?php

namespace App\Services;

use App\Models\Prescription;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owns the materialized queue (workout_session_items): generated once at
 * session start from the scheduler's due list, then owned by the runtime
 * (status changes, reorder). Set-level expansion is derived per request via
 * expandSetQueue() and never stored (hep-data-model.md rule 2).
 */
final class SessionService
{
    public function __construct(private readonly ScheduleService $schedule) {}

    /**
     * Today's open session if one exists (crash-safe resume), otherwise a new
     * session materialized from what's due. Null when nothing is due.
     */
    public function startOrResume(User $user, CarbonImmutable $date): ?WorkoutSession
    {
        $open = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->whereDate('planned_date', $date->toDateString())
            ->whereIn('status', ['planned', 'active'])
            ->first();

        if ($open !== null) {
            return $open;
        }

        $due = $this->schedule->dueOn($user, $date);

        if ($due->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($user, $date, $due): WorkoutSession {
            $session = WorkoutSession::create([
                'user_id' => $user->id,
                'program_version_id' => $due->first()->prescription->program_version_id,
                'planned_date' => $date->toDateString(),
                'started_at' => now(),
                'status' => 'active',
            ]);

            foreach ($due->values() as $sortOrder => $dueItem) {
                WorkoutSessionItem::create([
                    'workout_session_id' => $session->id,
                    'prescription_id' => $dueItem->prescription->id,
                    'sort_order' => $sortOrder,
                ]);
            }

            return $session;
        });
    }

    /**
     * Expand a prescription into the ordered set queue the player executes.
     * per_side doubles each set with a side switch (spec §2), except
     * alternating-laterality exercises, which alternate within a single set
     * (side stays NULL — hep-data-model.md open decision, doc leans simple).
     *
     * @return list<array{set_no: int, side: ?string, reps: ?int, hold_sec: ?int, eccentric_sec: ?int, duration_sec: ?int, rest_sec: ?int, to_fatigue: bool}>
     */
    public static function expandSetQueue(Prescription $prescription): array
    {
        $sides = $prescription->per_side && $prescription->exercise->laterality !== 'alternating'
            ? ['left', 'right']
            : [null];

        $queue = [];
        foreach (range(1, max(1, (int) ($prescription->sets ?? 1))) as $setNo) {
            foreach ($sides as $side) {
                $queue[] = [
                    'set_no' => $setNo,
                    'side' => $side,
                    'reps' => $prescription->reps,
                    'hold_sec' => $prescription->hold_sec,
                    'eccentric_sec' => $prescription->eccentric_sec,
                    'duration_sec' => $prescription->duration_sec,
                    'rest_sec' => $prescription->rest_sec,
                    'to_fatigue' => $prescription->to_fatigue,
                ];
            }
        }

        return $queue;
    }
}
