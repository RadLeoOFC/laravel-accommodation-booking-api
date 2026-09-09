<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            // UA: Унікальний slug запобігає конфліктам між тестовими записами.
            // EN: A unique slug prevents conflicts between test records.
            'slug' => 'supplier-' . Str::uuid(),
            'name' => fake()->company(),
        ];
    }
}