<?php

namespace App\Models;

use App\Support\DomainModel;

class Coupon extends DomainModel
{
    protected $table = 'coupons';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'starts_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
