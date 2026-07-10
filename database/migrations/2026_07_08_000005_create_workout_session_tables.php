<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Named workout_sessions (not sessions, as in hep-data-model.md) because Laravel's
// database session driver owns the `sessions` table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_version_id')->nullable()->constrained()->nullOnDelete();
            $table->date('planned_date');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->text('status')->default('planned'); // planned | active | done | abandoned
        });

        // The materialized queue the player walks through — crash-safe resume layer.
        Schema::create('workout_session_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescription_id')->constrained()->restrictOnDelete();
            $table->integer('sort_order');
            $table->text('status')->default('pending'); // pending | active | done | skipped
            $table->text('skip_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_session_items');
        Schema::dropIfExists('workout_sessions');
    }
};
