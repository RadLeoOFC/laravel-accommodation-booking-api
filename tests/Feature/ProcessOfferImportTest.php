<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessOfferImport;
use App\Models\Offer;
use App\Models\OfferImport;
use App\Models\Property;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProcessOfferImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UA: Фіксуємо час і створюємо постачальників для кожного тесту.
        // EN: Freeze time and seed suppliers before each test.
        $this->travelTo(
            Carbon::parse('2026-09-07T12:00:00Z')
        );

        $this->seed(SupplierSeeder::class);
    }

    // 1. Створення житла та пропозиції / Creating a property and an offer.
    public function test_it_creates_a_property_and_an_offer(): void
    {
        $offerData = $this->offerData();
        $import = $this->createImport([$offerData]);

        (new ProcessOfferImport($import->id))->handle();

        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('offers', 1);

        $property = Property::query()
            ->where('code', $offerData['property']['code'])
            ->firstOrFail();

        $this->assertSame(
            $offerData['property']['name'],
            $property->name
        );
        $this->assertSame(
            $offerData['property']['city'],
            $property->city
        );

        $offer = Offer::query()
            ->where('supplier_id', $import->supplier_id)
            ->where('external_id', $offerData['external_id'])
            ->firstOrFail();

        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'supplier_id' => $import->supplier_id,
            'property_id' => $property->id,
            'offer_import_id' => $import->id,
            'external_id' => $offerData['external_id'],
            'check_in' => '2026-11-10',
            'check_out' => '2026-11-15',
            'max_guests' => 8,
            'price' => '89500.00',
            'currency' => 'EUR',
            'available_units' => 4,
        ]);

        $this->assertTrue(
            Carbon::parse($offer->expires_at)
                ->equalTo($offerData['expires_at'])
        );

        $this->assertTrue($offer->property->is($property));
        $this->assertTrue($import->supplier->is($offer->supplier));
    }

    // 2. Спільне житло / Multiple offers for the same property.
    public function test_multiple_offers_share_one_property(): void
    {
        $firstOffer = $this->offerData();

        $secondOffer = $this->offerData([
            'external_id' => 'offer-a-10002',
            'price' => 85000,
        ]);

        $import = $this->createImport([
            $firstOffer,
            $secondOffer,
        ]);

        (new ProcessOfferImport($import->id))->handle();

        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('offers', 2);

        $property = Property::query()->firstOrFail();

        $this->assertEquals(
            2,
            Offer::query()
                ->where('property_id', $property->id)
                ->where('offer_import_id', $import->id)
                ->count()
        );

        $this->assertEquals(
            2,
            $import->fresh()->processed_offers
        );
    }

    // 3. Оновлення пропозиції / Updating an existing offer.
    public function test_a_new_import_updates_an_existing_offer(): void
    {
        $firstImport = $this->createImport([
            $this->offerData(),
        ]);

        (new ProcessOfferImport($firstImport->id))->handle();

        $originalOffer = Offer::query()->firstOrFail();
        $originalId = $originalOffer->id;
        $originalPropertyId = $originalOffer->property_id;

        $this->travelTo(
            Carbon::parse('2026-09-07T13:00:00Z')
        );

        $updatedData = $this->offerData([
            'check_in' => '2026-12-10',
            'check_out' => '2026-12-15',
            'max_guests' => 6,
            'price' => 79999.50,
            'currency' => 'USD',
            'available_units' => 2,
            'expires_at' => '2026-11-10T23:59:59Z',
        ]);

        $secondImport = $this->createImport(
            [$updatedData],
            'supplier-a',
            '2026-09-07T12:30:00Z'
        );

        (new ProcessOfferImport($secondImport->id))->handle();

        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('offers', 1);

        $this->assertDatabaseHas('offers', [
            'id' => $originalId,
            'supplier_id' => $secondImport->supplier_id,
            'property_id' => $originalPropertyId,
            'offer_import_id' => $secondImport->id,
            'external_id' => $updatedData['external_id'],
            'check_in' => '2026-12-10',
            'check_out' => '2026-12-15',
            'max_guests' => 6,
            'price' => '79999.50',
            'currency' => 'USD',
            'available_units' => 2,
        ]);

        $updatedOffer = $originalOffer->fresh();

        $this->assertTrue(
            Carbon::parse($updatedOffer->expires_at)
                ->equalTo($updatedData['expires_at'])
        );

        $this->assertSame(
            ImportStatus::Completed,
            $secondImport->fresh()->status
        );
    }

    // 4. Різні постачальники / Identical external IDs across suppliers.
    public function test_same_external_id_is_allowed_for_different_suppliers(): void
    {
        $offerData = $this->offerData();

        $firstImport = $this->createImport(
            [$offerData],
            'supplier-a'
        );

        $secondImport = $this->createImport(
            [$offerData],
            'supplier-b'
        );

        (new ProcessOfferImport($firstImport->id))->handle();
        (new ProcessOfferImport($secondImport->id))->handle();

        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('offers', 2);

        $this->assertDatabaseHas('offers', [
            'supplier_id' => $firstImport->supplier_id,
            'external_id' => $offerData['external_id'],
            'offer_import_id' => $firstImport->id,
        ]);

        $this->assertDatabaseHas('offers', [
            'supplier_id' => $secondImport->supplier_id,
            'external_id' => $offerData['external_id'],
            'offer_import_id' => $secondImport->id,
        ]);
    }

    // 5. Успішне завершення / Successful completion metadata.
    public function test_it_marks_the_import_as_completed(): void
    {
        $import = $this->createImport([
            $this->offerData(),
            $this->offerData([
                'external_id' => 'offer-a-10002',
            ]),
        ]);

        // UA: Перевіряємо очищення помилки після успішної повторної обробки.
        // EN: Verify that successful reprocessing clears an earlier error.
        $import->status = ImportStatus::Failed;
        $import->error = 'Previous processing error.';
        $import->save();

        (new ProcessOfferImport($import->id))->handle();

        $import->refresh();

        $this->assertSame(
            ImportStatus::Completed,
            $import->status
        );

        $this->assertEquals(2, $import->total_offers);
        $this->assertEquals(2, $import->processed_offers);
        $this->assertNull($import->error);

        $this->assertNotNull($import->started_at);
        $this->assertNotNull($import->completed_at);

        $this->assertTrue($import->started_at->equalTo(now()));
        $this->assertTrue($import->completed_at->equalTo(now()));
    }

    // 6. Повтор завершеної Job / Reprocessing a completed import.
    public function test_completed_import_is_not_processed_again(): void
    {
        $import = $this->createImport([
            $this->offerData(),
        ]);

        $job = new ProcessOfferImport($import->id);
        $job->handle();

        $offer = Offer::query()->firstOrFail();

        // UA: Імітуємо зміну доступності після завершення імпорту.
        // EN: Simulate an availability change after import completion.
        $offer->available_units = 1;
        $offer->save();

        $offerBefore = $offer->fresh()->getRawOriginal();
        $importBefore = $import->fresh()->getRawOriginal();

        $this->travelTo(
            Carbon::parse('2026-09-07T13:00:00Z')
        );

        $job->handle();

        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('offers', 1);

        $this->assertSame(
            $offerBefore,
            $offer->fresh()->getRawOriginal()
        );

        $this->assertSame(
            $importBefore,
            $import->fresh()->getRawOriginal()
        );
    }

    // 7. Атомарність пакета / Rolling back the entire batch.
    public function test_an_error_on_the_second_offer_rolls_back_the_batch(): void
    {
        $import = $this->createImport([
            $this->offerData(),
            $this->offerData([
                'external_id' => 'offer-a-10002',
                'property' => [
                    'code' => 'BRG-5057',
                    'name' => 'Second Apartments',
                    'city' => 'Burgas',
                ],
            ]),
        ]);

        $attemptedOffers = [];
        $caughtException = null;

        // UA: Ізолюємо тимчасовий обробник подій від інших тестів.
        // EN: Isolate the temporary event listener from other tests.
        $originalDispatcher = Offer::getEventDispatcher();
        Offer::setEventDispatcher(clone $originalDispatcher);

        try {
            Offer::creating(
                function (Offer $offer) use (&$attemptedOffers): void {
                    $attemptedOffers[] = $offer->external_id;

                    if ($offer->external_id === 'offer-a-10002') {
                        // UA: Перша пропозиція вже збережена в транзакції.
                        // EN: The first offer has already been saved in the transaction.
                        $this->assertDatabaseHas('offers', [
                            'external_id' => 'offer-a-10001',
                        ]);

                        throw new RuntimeException(
                            'Simulated second offer failure.'
                        );
                    }
                }
            );

            try {
                (new ProcessOfferImport($import->id))->handle();
            } catch (RuntimeException $exception) {
                $caughtException = $exception;
            }
        } finally {
            Offer::setEventDispatcher($originalDispatcher);
        }

        $this->assertInstanceOf(
            RuntimeException::class,
            $caughtException
        );

        $this->assertSame(
            'Simulated second offer failure.',
            $caughtException->getMessage()
        );

        $this->assertSame(
            ['offer-a-10001', 'offer-a-10002'],
            $attemptedOffers
        );

        // UA: Відкочуються обидва об’єкти житла та перша пропозиція.
        // EN: Both properties and the first offer must be rolled back.
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('offers', 0);

        $import->refresh();

        // UA: Статус також відкочується; failed() викликається окремо.
        // EN: The status is also rolled back; failed() is invoked separately.
        $this->assertSame(
            ImportStatus::Pending,
            $import->status
        );
        $this->assertEquals(0, $import->processed_offers);
        $this->assertNull($import->started_at);
        $this->assertNull($import->completed_at);
        $this->assertNull($import->error);
    }

    // 8. Остаточна помилка / Persisting a final failure.
    public function test_failed_marks_the_import_as_failed(): void
    {
        $import = $this->createImport([
            $this->offerData(),
        ]);

        $exception = new RuntimeException(
            'Import processing failed after all attempts.'
        );

        (new ProcessOfferImport($import->id))->failed($exception);

        $import->refresh();

        $this->assertSame(
            ImportStatus::Failed,
            $import->status
        );

        $this->assertSame(
            $exception->getMessage(),
            $import->error
        );

        $this->assertEquals(0, $import->processed_offers);
        $this->assertNull($import->completed_at);

        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('offers', 0);
    }

    // 9. Захист успішного результату / Preserving a completed import.
    public function test_failed_does_not_overwrite_a_completed_import(): void
    {
        $import = $this->createImport([
            $this->offerData(),
        ]);

        $job = new ProcessOfferImport($import->id);
        $job->handle();

        $offer = Offer::query()->firstOrFail();

        $importBefore = $import->fresh()->getRawOriginal();
        $offerBefore = $offer->getRawOriginal();

        $this->travelTo(
            Carbon::parse('2026-09-07 13:00:00')
        );

        $job->failed(
            new RuntimeException('A duplicate Job failed.')
        );

        $this->assertSame(
            $importBefore,
            $import->fresh()->getRawOriginal()
        );

        $this->assertSame(
            $offerBefore,
            $offer->fresh()->getRawOriginal()
        );

        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('offers', 1);
    }

    private function createImport(
        array $offers,
        string $supplierSlug = 'supplier-a',
        string $sentAt = '2026-09-07T10:00:00Z'
    ): OfferImport {
        $supplier = Supplier::query()
            ->where('slug', $supplierSlug)
            ->firstOrFail();

        return OfferImport::query()->create([
            'supplier_id' => $supplier->id,
            'external_import_id' => 'test-' . Str::uuid(),
            'sent_at' => $sentAt,
            'status' => ImportStatus::Pending,
            'payload' => $offers,
            'total_offers' => count($offers),
            'processed_offers' => 0,
        ]);
    }

    private function offerData(array $overrides = []): array
    {
        return array_replace_recursive([
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
        ], $overrides);
    }
}