<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'supplier_id' => SupplierFactory::new(),
            'property_id' => PropertyFactory::new(),
            'external_id' => 'offer-' . Str::uuid(),

            // UA: Базова пропозиція має майбутні дати та доступний залишок.
            // EN: The default offer has future dates and available stock.
            'check_in' => now()->addMonth()->toDateString(),
            'check_out' => now()->addMonth()->addDays(5)->toDateString(),
            'max_guests' => 4,
            'price' => '100.00',
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => now()->addDay()->toJSON(),

            // UA: Створюємо імпорт із тим самим постачальником і даними пропозиції.
            // EN: Create an import with the same supplier and offer data.
            'offer_import_id' => function (array $attributes): int {
                $property = Property::query()
                    ->findOrFail($attributes['property_id']);

                $payload = [
                    [
                        'external_id' => $attributes['external_id'],
                        'property' => [
                            'code' => $property->code,
                            'name' => $property->name,
                            'city' => $property->city,
                        ],
                        'check_in' => $attributes['check_in'],
                        'check_out' => $attributes['check_out'],
                        'max_guests' => $attributes['max_guests'],
                        'price' => $attributes['price'],
                        'currency' => $attributes['currency'],
                        'available_units' => $attributes['available_units'],
                        'expires_at' => $attributes['expires_at'],
                    ],
                ];

                return OfferImportFactory::new()
                    ->completed()
                    ->create([
                        'supplier_id' => $attributes['supplier_id'],
                        'payload' => $payload,
                    ])
                    ->id;
            },
        ];
    }
}