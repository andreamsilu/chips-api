<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $base_fee
 * @property int $fixed_fee
 * @property int $price_per_km
 * @property int $minimum_fee
 */
class DeliveryPricingRule extends DomainModel
{
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer', 'fixed_fee' => 'integer', 'base_fee' => 'integer', 'price_per_km' => 'integer', 'minimum_fee' => 'integer', 'maximum_distance_meters' => 'integer'];
    }

    /** @return HasMany<DeliveryZone, $this> */
    public function zones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class);
    }
}
