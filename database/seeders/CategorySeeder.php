<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Beverages', 'description' => 'Soft drinks, juices, and bottled water.'],
            ['name' => 'Snacks', 'description' => 'Chips, biscuits, and packaged snacks.'],
            ['name' => 'Household', 'description' => 'Cleaning supplies and household essentials.'],
            ['name' => 'Electronics', 'description' => 'Small electronics and accessories.'],
            ['name' => 'Stationery', 'description' => 'Office and school supplies.'],
        ];

        foreach ($categories as $category) {
            Category::query()->create($category);
        }
    }
}
