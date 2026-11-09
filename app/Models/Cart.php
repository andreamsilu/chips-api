<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends DomainModel
{
    protected $table = 'carts';

    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
