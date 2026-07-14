<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends Model
{
    /**
     * The single source for every dosage field. Consumers: revision copy logic
     * (ProgramRevisionService), the admin grid props, and its validation rules —
     * a column added here propagates to all three (CONTENT-ADMIN.md decision 7A).
     */
    public const DOSAGE_COLUMNS = [
        'sets', 'reps', 'hold_sec', 'eccentric_sec', 'rest_sec',
        'per_side', 'to_fatigue', 'duration_sec',
        'frequency_kind', 'times_per_week', 'time_of_day',
        'sort_order', 'notes',
    ];

    public $timestamps = false;

    protected $fillable = [
        'program_version_id', 'exercise_id',
        'sets', 'reps', 'hold_sec', 'eccentric_sec', 'rest_sec',
        'per_side', 'to_fatigue', 'duration_sec',
        'frequency_kind', 'times_per_week', 'time_of_day',
        'sort_order', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'per_side' => 'boolean',
            'to_fatigue' => 'boolean',
        ];
    }

    public function programVersion(): BelongsTo
    {
        return $this->belongsTo(ProgramVersion::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function dosageSummary(): string
    {
        $parts = [];

        if ($this->sets !== null) {
            $parts[] = "{$this->sets} sets";
        }
        if ($this->reps !== null) {
            $parts[] = "{$this->reps} reps";
        }
        if ($this->hold_sec !== null) {
            $parts[] = "{$this->hold_sec}s hold";
        }
        if ($this->eccentric_sec !== null) {
            $parts[] = "{$this->eccentric_sec}s eccentric";
        }
        if ($this->duration_sec !== null) {
            $parts[] = "{$this->duration_sec}s";
        }
        if ($this->to_fatigue) {
            $parts[] = 'to fatigue';
        }
        if ($this->per_side) {
            $parts[] = 'per side';
        }

        return implode(' / ', $parts);
    }
}
