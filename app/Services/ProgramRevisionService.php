<?php

namespace App\Services;

use App\Exceptions\StaleRevisionException;
use App\Models\Prescription;
use App\Models\Program;
use App\Models\ProgramVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The one write path for program changes (hep-data-model.md rule 1):
 * prescriptions are immutable-per-version — a revision copies rows into a
 * new version, never updates them in place.
 *
 *   revise(program, baseVersionNo, sourceNote, rows)
 *     │ DB::transaction
 *     ├─ lock programs row ................ parent mutex: serializes revisions
 *     ├─ current = whereNull(effective_to)  RuntimeException if missing
 *     ├─ current.version_no ≠ base? ....... StaleRevisionException (stale grid)
 *     ├─ close current: effective_to = today − 1
 *     │    same-day re-revision ⇒ empty window ("never ran" for scheduling);
 *     │    workout_sessions.program_version_id stays authoritative for history
 *     ├─ create version(version_no + 1, effective_from = today)
 *     └─ insert rows: exercise_id + Prescription::DOSAGE_COLUMNS each
 *
 * effective_from is ALWAYS today (CONTENT-ADMIN.md decision 2A) — no
 * future-dating, no backdating, and a bad revision is correctable the
 * minute it's noticed.
 */
final class ProgramRevisionService
{
    /**
     * @param list<array<string, mixed>> $rows one per prescription: exercise_id + DOSAGE_COLUMNS
     */
    public function revise(Program $program, int $baseVersionNo, ?string $sourceNote, array $rows): ProgramVersion
    {
        $next = DB::transaction(function () use ($program, $baseVersionNo, $sourceNote, $rows): ProgramVersion {
            Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();

            $current = $program->versions()->whereNull('effective_to')->first();
            if ($current === null) {
                throw new RuntimeException(
                    "program {$program->id} has no open version (effective_to IS NULL) — invariant breach, refusing to revise"
                );
            }
            if ($current->version_no !== $baseVersionNo) {
                throw new StaleRevisionException($current->version_no, $baseVersionNo);
            }

            $today = CarbonImmutable::today();
            $current->effective_to = $today->subDay();
            $current->save();

            $next = $program->versions()->create([
                'version_no' => $current->version_no + 1,
                'source_note' => $sourceNote,
                'effective_from' => $today,
            ]);

            $allowed = array_flip(Prescription::DOSAGE_COLUMNS);
            foreach ($rows as $row) {
                $next->prescriptions()->create([
                    'exercise_id' => $row['exercise_id'],
                    ...array_intersect_key($row, $allowed),
                ]);
            }

            return $next;
        });

        Log::info('content.edit', [
            'action' => 'program.revise',
            'program_id' => $program->id,
            'version_no' => $next->version_no,
            'base_version_no' => $baseVersionNo,
            'row_count' => count($rows),
            'source_note' => $sourceNote,
        ]);

        return $next->load('prescriptions');
    }
}
