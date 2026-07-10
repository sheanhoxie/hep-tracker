<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutSession extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'program_version_id', 'planned_date', 'started_at', 'ended_at', 'status'];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function programVersion(): BelongsTo
    {
        return $this->belongsTo(ProgramVersion::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkoutSessionItem::class)->orderBy('sort_order');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }
}
