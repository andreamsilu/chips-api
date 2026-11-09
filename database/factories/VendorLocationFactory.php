<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorLocation> */
class VendorLocationFactory extends Factory
{
    protected $model = VendorLocation::class;

    public function definition(): array
    {
        return ['vendor_id' => Vendor::factory(), 'name' => 'Mikocheni', 'address' => 'Mikocheni, Dar es Salaam', 'latitude' => -6.77, 'longitude' => 39.25, 'accepting_orders' => true, 'delivery_enabled' => true, 'pickup_enabled' => true, 'delivery_radius_km' => 15];
    }
}
