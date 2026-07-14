<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One stock row per (exercise_id, kind) — controllers read firstWhere('kind', …),
        // so a duplicate row would silently shadow. form_check exempt: multi-row per
        // exercise (per user recording) by design.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX exercise_media_stock_unique
            ON exercise_media (exercise_id, kind)
            WHERE kind <> 'form_check'
        SQL);

        Schema::table('exercise_media', function (Blueprint $table) {
            $table->timestampsTz();
        });

        Schema::table('workout_session_items', function (Blueprint $table) {
            $table->index('prescription_id');
        });

        // Additive-only view change: appends exercise_id, existing columns and
        // grain unchanged — old releases keep serving during the pre-swap
        // migrate window (deploy.sh migrates before the symlink swap).
        // prescription_id is NOT NULL, so the inner join is 1:1 (no grain change).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_daily_completions AS
            SELECT s.user_id, s.planned_date, si.prescription_id, si.status, p.exercise_id
            FROM workout_sessions s
            JOIN workout_session_items si ON si.workout_session_id = s.id
            JOIN prescriptions p ON p.id = si.prescription_id
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_daily_completions');
        DB::statement(<<<'SQL'
            CREATE VIEW v_daily_completions AS
            SELECT s.user_id, s.planned_date, si.prescription_id, si.status
            FROM workout_sessions s
            JOIN workout_session_items si ON si.workout_session_id = s.id
        SQL);

        Schema::table('workout_session_items', function (Blueprint $table) {
            $table->dropIndex(['prescription_id']);
        });

        Schema::table('exercise_media', function (Blueprint $table) {
            $table->dropTimestampsTz();
        });

        DB::statement('DROP INDEX IF EXISTS exercise_media_stock_unique');
    }
};
