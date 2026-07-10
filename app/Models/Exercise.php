<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    protected $fillable = [
        'slug', 'canonical_name', 'description', 'steps', 'body_region',
        'movement_pattern', 'laterality', 'equipment', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'equipment' => 'array',
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ExerciseAlias::class);
    }

    public function cues(): HasMany
    {
        return $this->hasMany(ExerciseCue::class)->orderBy('sort_order');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ExerciseMedia::class)->orderBy('sort_order');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }
}
