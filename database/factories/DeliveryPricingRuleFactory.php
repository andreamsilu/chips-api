<?php

namespace Database\Factories;

use App\Models\DeliveryPricingRule;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryPricingRule> */
class DeliveryPricingRuleFactory extends Factory
{
    protected $model = DeliveryPricingRule::class;

    public function definition(): array
    {
        return ['scope_key' => fake()->unique()->uuid(), 'vendor_id' => Vendor::factory(), 'pricing_method' => 'per_km', 'fixed_fee' => 0, 'base_fee' => 2000, 'price_per_km' => 500, 'minimum_fee' => 0, 'maximum_distance_meters' => 15000, 'active' => true, 'version' => 1];
    }
}
