<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['program_id', 'version_no', 'source_note', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class)->orderBy('sort_order');
    }
}
