<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only: no UPDATE, no DELETE. Replayed from the player's offline
// queue at-least-once; client_key dedupes (stack-decisions.md rule 4).
class SetEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workout_session_item_id', 'client_key', 'set_no', 'side',
        'reps_done', 'hold_sec_done', 'completed', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function sessionItem(): BelongsTo
    {
        return $this->belongsTo(WorkoutSessionItem::class, 'workout_session_item_id');
    }
}
