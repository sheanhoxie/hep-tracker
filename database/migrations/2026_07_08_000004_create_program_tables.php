<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('title');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('program_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->integer('version_no');
            $table->text('source_note')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // NULL = current
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['program_id', 'version_no']);
        });

        // Immutable per version: a revision copies rows into a new program_version,
        // never edits these in place (hep-data-model.md rule 1).
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();

            $table->integer('sets')->nullable();
            $table->integer('reps')->nullable();
            $table->integer('hold_sec')->nullable();
            $table->integer('eccentric_sec')->nullable();
            $table->integer('rest_sec')->nullable();
            $table->boolean('per_side')->default(false);
            $table->boolean('to_fatigue')->default(false);
            $table->integer('duration_sec')->nullable();

            $table->text('frequency_kind'); // daily | per_week | as_needed
            $table->integer('times_per_week')->nullable();
            $table->text('time_of_day')->nullable(); // morning | evening | any

            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('program_versions');
        Schema::dropIfExists('programs');
    }
};
