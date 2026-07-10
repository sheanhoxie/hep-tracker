<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Versioning core (hep-data-model.md §3):
 *
 *   programs (stable identity: "Patient's knee rehab")
 *      └── program_versions (dated revisions; effective_to NULL = current)
 *             └── prescriptions (immutable per version — revise by copying
 *                 rows into a new version, never editing in place)
 */
class Program extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['patient_id', 'author_id', 'title'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProgramVersion::class)->orderBy('version_no');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(ProgramVersion::class)->whereNull('effective_to');
    }
}
