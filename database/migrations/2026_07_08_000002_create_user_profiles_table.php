<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('affected_side')->nullable();   // left | right | bilateral
            $table->text('injury_context')->nullable();
            $table->jsonb('equipment')->default('[]');
            $table->jsonb('preferences')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
