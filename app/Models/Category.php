<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends DomainModel
{
    protected $table = 'categories';

    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
