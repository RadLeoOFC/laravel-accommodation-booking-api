<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\OfferImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OfferImport>
 */
class OfferImportFactory extends Factory
{
    protected $model = OfferImport::class;

    public function definition(): array
    {
        return [
            'supplier_id' => SupplierFactory::new(),
            'external_import_id' => 'import-' . Str::uuid(),
            'sent_at' => now()->subMinutes(2),
            'status' => ImportStatus::Pending,

            // UA: Зберігаємо структуру пакета, яку очікує обробник імпорту.
            // EN: Store the batch structure expected by the import processor.
            'payload' => [
                [
                    'external_id' => 'offer-' . Str::uuid(),
                    'property' => [
                        'code' => 'TEST-' . Str::uuid(),
                        'name' => 'Test Apartment',
                        'city' => 'Barcelona',
                    ],
                    'check_in' => now()->addMonth()->toDateString(),
                    'check_out' => now()->addMonth()->addDays(5)->toDateString(),
                    'max_guests' => 4,
                    'price' => '100.00',
                    'currency' => 'EUR',
                    'available_units' => 2,
                    'expires_at' => now()->addDay()->toJSON(),
                ],
            ],

            // UA: Лічильник враховує payload, перевизначений у тесті.
            // EN: The counter accounts for a payload overridden by the test.
            'total_offers' => fn (array $attributes): int =>
                count($attributes['payload']),

            'processed_offers' => 0,
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        // UA: Готуємо метадані завершеного імпорту без виконання Job.
        // EN: Prepare completed import metadata without executing the job.
        return $this->state(fn (array $attributes): array => [
            'status' => ImportStatus::Completed,
            'processed_offers' => count($attributes['payload']),
            'error' => null,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }
}