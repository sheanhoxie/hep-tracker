<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseAlias extends Model
{
    public $timestamps = false;

    protected $fillable = ['exercise_id', 'user_id', 'alias', 'is_display_name'];

    protected function casts(): array
    {
        return ['is_display_name' => 'boolean'];
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
