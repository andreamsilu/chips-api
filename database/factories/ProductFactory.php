<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\VendorLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return ['vendor_location_id' => VendorLocation::factory(), 'category_id' => Category::factory(), 'name' => 'Chips Kuku', 'price' => 10000, 'stock' => 20, 'available' => true];
    }
}
