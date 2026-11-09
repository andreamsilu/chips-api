<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rider extends DomainModel
{
    protected $table = 'riders';

    protected function casts(): array
    {
        return [];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
