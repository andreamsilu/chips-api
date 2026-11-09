<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends DomainModel
{
    use SoftDeletes;

    protected $table = 'products';

    protected function casts(): array
    {
        return ['available' => 'boolean', 'price' => 'integer', 'stock' => 'integer'];
    }

    /** @return BelongsTo<VendorLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(VendorLocation::class, 'vendor_location_id');
    }
}
