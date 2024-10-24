<?php

namespace Database\Factories;

use App\Models\Locations;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Locations>
 */
class LocationsFactory extends Factory
{
    protected $model = Locations::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->city,  // For example, use Faker to generate a city name
            'immediate_inventory' => $this->faker->randomElement(['Y', 'N']),
            'sameday_preorder_end_time' => now()->addHours(2),  // Example: Preorder end time 2 hours from now
        ];
    }
}
