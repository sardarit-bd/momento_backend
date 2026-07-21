<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'address1' => fake()->streetAddress(),
            'address2' => null,
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => fake()->country(),
            'zipcode' => fake()->postcode(),
            'total' => (string) fake()->randomFloat(2, 10, 500),
            'status' => 'completed',
            'is_paid' => true,
            'is_customized' => false,
            'tgc_receipt_id' => null,
        ];
    }
}
