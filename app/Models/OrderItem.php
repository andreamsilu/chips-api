<?php

namespace App\Models;

use App\Support\AppendOnly;
use App\Support\DomainModel;

class OrderItem extends DomainModel
{
    use AppendOnly;

    protected $table = 'order_items';

    protected function casts(): array
    {
        return [];
    }
}
