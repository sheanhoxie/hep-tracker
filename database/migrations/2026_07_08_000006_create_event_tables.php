<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Append-only: never UPDATE, never DELETE (hep-data-model.md rule 1).
// client_key implements stack-decisions.md rule 4: the player's offline write
// queue replays at-least-once; the server dedupes on this key.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('set_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_session_item_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_key')->nullable()->unique();
            $table->integer('set_no');
            $table->text('side')->nullable(); // left | right | NULL
            $table->integer('reps_done')->nullable();
            $table->integer('hold_sec_done')->nullable();
            $table->boolean('completed')->default(true);
            $table->timestampTz('occurred_at')->useCurrent();
        });

        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workout_session_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('client_key')->nullable()->unique();
            $table->text('kind'); // pain | rpe | note
            $table->decimal('numeric_value', 5, 2)->nullable();
            $table->text('text_value')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
        });

        DB::statement(
            'ALTER TABLE observations ADD CONSTRAINT observations_grain_check
             CHECK (workout_session_id IS NOT NULL OR workout_session_item_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
        Schema::dropIfExists('set_events');
    }
};
