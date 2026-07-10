<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\ExerciseCue;
use App\Models\ExerciseMedia;
use App\Models\Prescription;
use App\Models\Program;
use App\Models\ProgramVersion;
use App\Models\Reminder;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds the real the PT HEP (sample date) from program-source-document
 * Appendix A (database/seeders/data/hep-exercises.json).
 *
 * Per spec §6: all exercises into the library; one program + version 1;
 * prescriptions for every exercise (unscheduled items get frequency_kind
 * 'as_needed' so they can be promoted in a later version). set_events are
 * never seeded — the log starts empty.
 *
 * Data gaps flagged in spec §5 are preserved in prescriptions.notes.
 */
class HepProgramSeeder extends Seeder
{
    private const FREQUENCY_BY_GROUP = [
        'daily' => 'daily',
        'weekly' => 'per_week',
        'unscheduled' => 'as_needed',
    ];

    public function run(): void
    {
        $catalog = json_decode(
            (string) file_get_contents(database_path('seeders/data/hep-exercises.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        // Placeholder demo videos (wger.de CC-BY-SA, Wikimedia Commons CC-BY) — real form videos pending.
        $videos = json_decode(
            (string) file_get_contents(database_path('seeders/data/hep-media.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['videos'];

        $patient = User::firstOrCreate(
            ['email' => 'patient@example.com'],
            ['name' => 'Patient', 'password' => Hash::make('password'), 'role' => 'patient', 'email_verified_at' => now()],
        );

        UserProfile::firstOrCreate(
            ['user_id' => $patient->id],
            [
                'affected_side' => 'right',
                'injury_context' => 'Knee rehabilitation (sample data)',
                'equipment' => ['loop band', 'ball', 'yoga block', 'chair', 'step', 'heel wedge'],
                'preferences' => ['units' => 'imperial', 'default_rest_sec' => 30],
            ],
        );

        Reminder::firstOrCreate(
            ['user_id' => $patient->id, 'kind' => 'daily_nudge'],
            ['schedule' => '17:00', 'enabled' => true],
        );

        $exercises = [];
        foreach ($catalog as $entry) {
            $exercise = Exercise::firstOrCreate(
                ['slug' => $entry['slug']],
                [
                    'canonical_name' => $entry['name'],
                    'body_region' => $entry['region'],
                    'movement_pattern' => $entry['pattern'],
                    'laterality' => $entry['laterality'],
                    'steps' => $entry['form'],
                    'equipment' => $entry['equipment'],
                ],
            );
            $exercises[$entry['slug']] = $exercise;

            foreach ($entry['aliases'] as $i => $alias) {
                ExerciseAlias::firstOrCreate(
                    ['user_id' => $patient->id, 'exercise_id' => $exercise->id, 'alias' => $alias],
                    ['is_display_name' => $i === 0],
                );
            }

            foreach ($entry['cues'] as $i => $cue) {
                ExerciseCue::firstOrCreate(
                    ['exercise_id' => $exercise->id, 'user_id' => null, 'cue' => $cue],
                    ['sort_order' => $i],
                );
            }

            ExerciseMedia::firstOrCreate(
                ['exercise_id' => $exercise->id, 'kind' => 'video'],
                ['url' => $videos[$entry['slug']], 'sort_order' => 0],
            );
            ExerciseMedia::firstOrCreate(
                ['exercise_id' => $exercise->id, 'kind' => 'image'],
                ['url' => "/thumbs/{$entry['slug']}.svg", 'sort_order' => 0],
            );
        }

        $program = Program::firstOrCreate(
            ['patient_id' => $patient->id, 'title' => 'Sample Rehab Program'],
        );

        $version = ProgramVersion::firstOrCreate(
            ['program_id' => $program->id, 'version_no' => 1],
            [
                'source_note' => 'the PT HEP, sample date',
                'effective_from' => '2026-06-18',
            ],
        );

        if ($version->prescriptions()->exists()) {
            return;
        }

        foreach (array_values($catalog) as $sortOrder => $entry) {
            $frequency = self::FREQUENCY_BY_GROUP[$entry['group']]
                ?? throw new RuntimeException("Unknown frequency group '{$entry['group']}' for {$entry['slug']}");

            Prescription::create([
                'program_version_id' => $version->id,
                'exercise_id' => $exercises[$entry['slug']]->id,
                'sets' => $entry['sets'],
                'reps' => $entry['reps'],
                'hold_sec' => $entry['hold_sec'],
                'eccentric_sec' => $entry['eccentric_sec'],
                'rest_sec' => $entry['rest_sec'],
                'per_side' => $entry['per_side'],
                'to_fatigue' => $entry['to_fatigue'],
                'duration_sec' => $entry['duration_sec'],
                'frequency_kind' => $frequency,
                'times_per_week' => $entry['times_per_week'],
                'sort_order' => $sortOrder,
                'notes' => $entry['notes'],
            ]);
        }
    }
}
