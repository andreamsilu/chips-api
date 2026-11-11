<?php

namespace Database\Factories;

use App\Models\DeliveryPricingRule;
use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryZone> */
class DeliveryZoneFactory extends Factory
{
    protected $model = DeliveryZone::class;

    public function definition(): array
    {
        return ['delivery_pricing_rule_id' => DeliveryPricingRule::factory(), 'name' => 'Local zone', 'maximum_distance_meters' => 5000, 'fee' => 3000, 'active' => true];
    }
}
