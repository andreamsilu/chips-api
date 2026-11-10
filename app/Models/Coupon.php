<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends DomainModel
{
    protected $table = 'coupons';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'starts_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
