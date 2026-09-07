<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessOfferImport;
use App\Models\OfferImport;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OfferImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UA: Фіксуємо час для відтворюваності тестів.
        // EN: Freeze time to keep the tests reproducible.
        $this->travelTo(
            \Illuminate\Support\Carbon::parse('2026-09-07T12:00:00Z')
        );

        // UA: Перехоплюємо Job без виконання handle().
        // EN: Capture dispatched Jobs without executing handle().
        Queue::fake();

        // UA: Створюємо постачальників у тестовій базі.
        // EN: Create suppliers in the test database.
        $this->seed(SupplierSeeder::class);
    }

    public function test_it_accepts_an_import_and_dispatches_a_job(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson('/api/imports', $payload);

        $response
            ->assertStatus(202)
            ->assertJsonStructure([
                'data' => ['id', 'status'],
            ])
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('offer_imports', 1);

        $import = OfferImport::query()->findOrFail(
            $response->json('data.id')
        );

        $supplier = Supplier::query()
            ->where('slug', 'supplier-a')
            ->firstOrFail();

        $this->assertEquals($supplier->id, $import->supplier_id);
        $this->assertSame(
            $payload['external_import_id'],
            $import->external_import_id
        );
        $this->assertTrue(
            $import->sent_at->equalTo($payload['sent_at'])
        );
        $this->assertSame(ImportStatus::Pending, $import->status);
        $this->assertSame($payload['offers'], $import->payload);
        $this->assertEquals(1, $import->total_offers);
        $this->assertEquals(0, $import->processed_offers);

        $this->assertNull($import->started_at);
        $this->assertNull($import->completed_at);
        $this->assertNull($import->error);

        Queue::assertPushed(ProcessOfferImport::class, 1);

        Queue::assertPushedOn(
            'imports',
            ProcessOfferImport::class,
            fn (ProcessOfferImport $job): bool =>
                $job->importId === $import->id
        );

        // UA: Контролер приймає пакет, але не обробляє пропозиції.
        // EN: The controller accepts the batch but does not process offers.
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('offers', 0);

        // UA: Вхідний пакет не повинен потрапляти у відповідь.
        // EN: The incoming payload must not be exposed in the response.
        $response->assertJsonMissingPath('data.payload');
    }

    public function test_repeated_import_does_not_create_duplicates(): void
    {
        $payload = $this->validPayload();

        $firstResponse = $this->postJson('/api/imports', $payload);
        $firstResponse->assertStatus(202);

        $secondResponse = $this->postJson('/api/imports', $payload);

        $secondResponse
            ->assertStatus(202)
            ->assertJsonPath(
                'data.id',
                $firstResponse->json('data.id')
            );

        $this->assertDatabaseCount('offer_imports', 1);

        Queue::assertPushed(ProcessOfferImport::class, 1);
    }

    public function test_repeated_import_does_not_overwrite_original_data(): void
    {
        $original = $this->validPayload();

        $firstResponse = $this->postJson('/api/imports', $original);
        $firstResponse->assertStatus(202);

        $changed = $original;
        $changed['sent_at'] = '2026-09-07T11:00:00Z';
        $changed['offers'][0]['price'] = 12345;

        $this->postJson('/api/imports', $changed)
            ->assertStatus(202)
            ->assertJsonPath(
                'data.id',
                $firstResponse->json('data.id')
            );

        $import = OfferImport::query()->findOrFail(
            $firstResponse->json('data.id')
        );

        $this->assertSame($original['offers'], $import->payload);
        $this->assertTrue(
            $import->sent_at->equalTo($original['sent_at'])
        );
        $this->assertDatabaseCount('offer_imports', 1);

        Queue::assertPushed(ProcessOfferImport::class, 1);
    }

    public function test_same_external_import_id_is_allowed_for_different_suppliers(): void
    {
        $firstPayload = $this->validPayload();
        $secondPayload = $firstPayload;
        $secondPayload['supplier'] = 'supplier-b';

        $firstResponse = $this->postJson('/api/imports', $firstPayload);
        $secondResponse = $this->postJson('/api/imports', $secondPayload);

        $firstResponse->assertStatus(202);
        $secondResponse->assertStatus(202);

        $this->assertNotSame(
            $firstResponse->json('data.id'),
            $secondResponse->json('data.id')
        );

        $this->assertDatabaseCount('offer_imports', 2);

        Queue::assertPushed(ProcessOfferImport::class, 2);
    }

    public function test_repeated_completed_import_is_not_restarted(): void
    {
        $payload = $this->validPayload();

        $firstResponse = $this->postJson('/api/imports', $payload);
        $firstResponse->assertStatus(202);

        $import = OfferImport::query()->findOrFail(
            $firstResponse->json('data.id')
        );

        // UA: Імітуємо стан після успішної роботи worker.
        // EN: Simulate the state after successful worker processing.
        $import->status = ImportStatus::Completed;
        $import->processed_offers = 1;
        $import->started_at = now();
        $import->completed_at = now();
        $import->save();

        $this->postJson('/api/imports', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(
            ImportStatus::Completed,
            $import->fresh()->status
        );

        Queue::assertPushed(ProcessOfferImport::class, 1);
    }

    public function test_unknown_supplier_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['supplier'] = 'unknown-supplier';

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier']);

        $this->assertDatabaseCount('offer_imports', 0);

        Queue::assertNothingPushed();
    }

    public function test_missing_external_offer_id_is_rejected(): void
    {
        $payload = $this->validPayload();

        unset($payload['offers'][0]['external_id']);

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offers.0.external_id']);

        $this->assertDatabaseCount('offer_imports', 0);

        Queue::assertNothingPushed();
    }

    public function test_invalid_offer_values_are_rejected(): void
    {
        $payload = $this->validPayload();

        $payload['offers'][0]['property']['code'] = '';
        $payload['offers'][0]['check_out'] = '2026-11-09';
        $payload['offers'][0]['max_guests'] = 0;
        $payload['offers'][0]['price'] = -1;
        $payload['offers'][0]['currency'] = 'EURO';
        $payload['offers'][0]['available_units'] = -1;
        $payload['offers'][0]['expires_at'] = 'not-a-date';

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'offers.0.property.code',
                'offers.0.check_out',
                'offers.0.max_guests',
                'offers.0.price',
                'offers.0.currency',
                'offers.0.available_units',
                'offers.0.expires_at',
            ]);

        $this->assertDatabaseCount('offer_imports', 0);
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('offers', 0);

        Queue::assertNothingPushed();
    }

    public function test_empty_offers_array_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['offers'] = [];

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offers']);

        $this->assertDatabaseCount('offer_imports', 0);

        Queue::assertNothingPushed();
    }

    public function test_duplicate_external_ids_within_a_batch_are_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['offers'][] = $payload['offers'][0];

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offers.0.external_id']);

        $this->assertDatabaseCount('offer_imports', 0);

        Queue::assertNothingPushed();
    }

    private function validPayload(): array
    {
        // UA: Спільний валідний пакет для перевірки endpoint.
        // EN: A shared valid payload for testing the endpoint.
        return [
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-07-001',
            'sent_at' => '2026-09-07T10:00:00Z',
            'offers' => [
                [
                    'external_id' => 'offer-a-10001',
                    'property' => [
                        'code' => 'BRG-5056',
                        'name' => 'Samuil Apartments',
                        'city' => 'Burgas',
                    ],
                    'check_in' => '2026-11-10',
                    'check_out' => '2026-11-15',
                    'max_guests' => 8,
                    'price' => 89500,
                    'currency' => 'EUR',
                    'available_units' => 4,
                    'expires_at' => '2026-10-10T23:59:59Z',
                ],
            ],
        ];
    }
}