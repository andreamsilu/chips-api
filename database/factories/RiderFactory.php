<?php

namespace Database\Factories;

use App\Models\Rider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Rider> */
class RiderFactory extends Factory
{
    protected $model = Rider::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'vehicle' => 'Motorcycle', 'status' => 'approved'];
    }
}
