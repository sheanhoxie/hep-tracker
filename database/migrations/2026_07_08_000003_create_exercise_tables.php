<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->text('slug')->unique();
            $table->text('canonical_name');
            $table->text('description')->nullable();
            $table->jsonb('steps')->default('[]');
            $table->text('body_region')->nullable();       // core | hip | knee | ankle-foot
            $table->text('movement_pattern')->nullable();  // squat | hinge | isometric | gait | balance
            $table->text('laterality')->default('bilateral'); // bilateral | unilateral | alternating
            $table->jsonb('equipment')->default('[]');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('CREATE INDEX exercises_equipment_gin ON exercises USING GIN (equipment)');

        Schema::create('exercise_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('alias');
            $table->boolean('is_display_name')->default(false);
            $table->unique(['user_id', 'exercise_id', 'alias']);
        });

        Schema::create('exercise_cues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // NULL = stock cue
            $table->text('cue');
            $table->integer('sort_order')->default(0);
        });

        Schema::create('exercise_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->text('kind'); // video | gif | image | form_check
            $table->text('url');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('duration_sec')->nullable();
            $table->integer('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_media');
        Schema::dropIfExists('exercise_cues');
        Schema::dropIfExists('exercise_aliases');
        Schema::dropIfExists('exercises');
    }
};
