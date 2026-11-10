<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return ['name' => fake()->words(3, true), 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(7), 'active' => true];
    }
}
