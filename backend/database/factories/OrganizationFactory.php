<?php
namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'type' => 'real_estate_client',
            'business_name' => $this->faker->company() . ' Properties',
            'contact_person' => $this->faker->name(),
            'email' => $this->faker->unique()->companyEmail(),
            'phone_e164' => '+92300' . $this->faker->numerify('#######'),
            'country' => 'Pakistan',
            'city' => $this->faker->randomElement(['Karachi', 'Lahore', 'Islamabad']),
            'timezone' => 'Asia/Karachi',
            'currency' => 'PKR',
            'status' => 'active',
        ];
    }

    public function agency(): static
    {
        return $this->state(fn () => ['type' => 'agency']);
    }
}
