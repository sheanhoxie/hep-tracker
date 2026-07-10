<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseCue extends Model
{
    public $timestamps = false;

    protected $fillable = ['exercise_id', 'user_id', 'cue', 'sort_order'];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
