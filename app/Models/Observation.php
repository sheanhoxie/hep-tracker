<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only subjective data (pain | rpe | note), attachable at session or
// item grain — DB enforces at least one of the two.
class Observation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'workout_session_id', 'workout_session_item_id', 'client_key',
        'kind', 'numeric_value', 'text_value', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'numeric_value' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    public function sessionItem(): BelongsTo
    {
        return $this->belongsTo(WorkoutSessionItem::class, 'workout_session_item_id');
    }
}
