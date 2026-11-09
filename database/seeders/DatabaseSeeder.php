<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (['Chips', 'Chicken', 'Grill', 'Burgers', 'Wraps', 'Drinks'] as $name) {
            if (! Category::where('name', $name)->exists()) {
                Category::record(['name' => $name]);
            }
        }
    }
}
