<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends DomainModel
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'starts_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return HasMany<Coupon, $this> */
    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}
