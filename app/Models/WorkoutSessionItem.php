<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutSessionItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['workout_session_id', 'prescription_id', 'sort_order', 'status', 'skip_reason'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function setEvents(): HasMany
    {
        return $this->hasMany(SetEvent::class)->orderBy('set_no');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }
}
