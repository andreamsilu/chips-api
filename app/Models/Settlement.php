<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends DomainModel
{
    protected $table = 'settlements';

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    /** @return HasMany<SettlementEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(SettlementEntry::class);
    }
}
