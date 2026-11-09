<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Address> */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'label' => 'Home', 'details' => 'Mikocheni B, Dar es Salaam', 'latitude' => -6.77, 'longitude' => 39.25];
    }
}
