<?php

namespace App\Models;

use App\Support\DomainModel;

class CouponRedemption extends DomainModel
{
    protected $table = 'coupon_redemptions';

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }
}
