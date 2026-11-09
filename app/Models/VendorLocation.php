<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorLocation extends DomainModel
{
    use SoftDeletes;

    protected $table = 'vendor_locations';

    protected function casts(): array
    {
        return ['accepting_orders' => 'boolean', 'delivery_enabled' => 'boolean', 'pickup_enabled' => 'boolean'];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
