<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        return [
            // UA: Кожен об’єкт житла отримує унікальний зовнішній код.
            // EN: Each property receives a unique external code.
            'code' => 'TEST-' . Str::uuid(),
            'name' => 'Test Apartment',

            // UA: Фіксоване місто робить базові сценарії передбачуваними.
            // EN: A fixed city makes default scenarios predictable.
            'city' => 'Barcelona',
        ];
    }
}