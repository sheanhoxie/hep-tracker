<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('kind'); // daily_nudge | weekly_deficit | pt_visit_prep
            $table->text('schedule');
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_sent_at')->nullable();
        });

        // Derived, never stored (hep-data-model.md rule 2).
        // Week boundary: ISO Monday via date_trunc — open decision, doc's assumption.
        DB::statement(<<<'SQL'
            CREATE VIEW v_weekly_completions AS
            SELECT si.prescription_id,
                   date_trunc('week', s.planned_date) AS week_start,
                   COUNT(DISTINCT s.planned_date)     AS days_done
            FROM workout_session_items si
            JOIN workout_sessions s ON s.id = si.workout_session_id
            WHERE si.status = 'done'
            GROUP BY 1, 2
        SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW v_daily_completions AS
            SELECT s.user_id, s.planned_date, si.prescription_id, si.status
            FROM workout_sessions s
            JOIN workout_session_items si ON si.workout_session_id = s.id
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_daily_completions');
        DB::statement('DROP VIEW IF EXISTS v_weekly_completions');
        Schema::dropIfExists('reminders');
    }
};
