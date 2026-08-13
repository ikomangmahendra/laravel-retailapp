<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $purchasePrice = $this->faker->randomFloat(2, 5, 500);

        return [
            'category_id' => Category::factory(),
            'sku' => strtoupper($this->faker->unique()->bothify('SKU-####??')),
            'name' => ucfirst($this->faker->words(3, true)),
            'purchase_price' => $purchasePrice,
            'selling_price' => round($purchasePrice * $this->faker->randomFloat(2, 1.1, 1.6), 2),
            'stock' => $this->faker->numberBetween(0, 200),
            'unit' => $this->faker->randomElement(['pcs', 'box', 'kg', 'pack']),
            'is_active' => $this->faker->boolean(90),
        ];
    }
}
