<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'kind', 'schedule', 'enabled', 'last_sent_at'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
