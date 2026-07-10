<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseMedia extends Model
{
    protected $table = 'exercise_media';

    public $timestamps = false;

    protected $fillable = ['exercise_id', 'kind', 'url', 'uploaded_by', 'duration_sec', 'sort_order'];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
