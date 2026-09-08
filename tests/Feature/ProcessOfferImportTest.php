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

        // UA: Фіксуємо час для відтворюваності тестів.
        // EN: Freeze time to keep the tests reproducible.
        $this->travelTo(
            Carbon::parse('2026-09-07T12:00:00Z')
        );

        // UA: Створюємо постачальників у тестовій базі.
        // EN: Create suppliers in the test database.
        $this->seed(SupplierSeeder::class);
    }

    // UA: Job обробляє імпорт і створює житло разом із пропозицією в базі.
    // EN: The Job processes an import and persists a property and an offer.
    public function test_it_creates_a_property_and_an_offer(): void
    {
        // UA: Створюємо імпорт із однією коректною пропозицією.
        // EN: Create an import containing one valid offer.
        $offerData = $this->offerData();
        $import = $this->createImport([$offerData]);

        // UA: Виконуємо Job напряму, без черги.
        // EN: Run the Job directly, bypassing the queue.
        (new ProcessOfferImport($import->id))->handle();

        // UA: Обробка має створити одне житло та одну пропозицію.
        // EN: Processing must create one property and one offer.
        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('offers', 1);

        $property = Property::query()
            ->where('code', $offerData['property']['code'])
            ->firstOrFail();

        // UA: Перевіряємо збережені атрибути житла з payload.
        // EN: Verify the persisted property attributes from the payload.
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

        // UA: Перевіряємо всі збережені поля пропозиції та зв’язки.
        // EN: Verify all persisted offer fields and relationships.
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

    // UA: Кілька пропозицій з однаковим property.code використовують одне житло.
    // EN: Multiple offers with the same property.code share a single property.
    public function test_multiple_offers_share_one_property(): void
    {
        // UA: Дві пропозиції посилаються на одне й те саме житло за code.
        // EN: Two offers reference the same property by code.
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

        // UA: Має існувати одне житло та дві окремі пропозиції.
        // EN: There must be one property and two distinct offers.
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

        // UA: Лічильник processed_offers відображає обидві оброблені пропозиції.
        // EN: The processed_offers counter reflects both processed offers.
        $this->assertEquals(
            2,
            $import->fresh()->processed_offers
        );
    }

    // UA: Новий імпорт того самого постачальника оновлює пропозицію з тим самим external_id без дублювання.
    // EN: A new import from the same supplier updates the offer with the same external_id without duplication.
    public function test_a_new_import_updates_an_existing_offer(): void
    {
        // UA: Перший імпорт створює початкову пропозицію.
        // EN: The first import creates the initial offer.
        $firstImport = $this->createImport([
            $this->offerData(),
        ]);

        (new ProcessOfferImport($firstImport->id))->handle();

        $originalOffer = Offer::query()->firstOrFail();
        $originalId = $originalOffer->id;
        $originalPropertyId = $originalOffer->property_id;

        // UA: Зсуваємо час перед другим імпортом.
        // EN: Advance time before the second import.
        $this->travelTo(
            Carbon::parse('2026-09-07T13:00:00Z')
        );

        // UA: Той самий external_id, але з оновленими полями пропозиції.
        // EN: Same external_id with updated offer fields.
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

        // UA: Оновлюється наявна пропозиція зі збереженням її ID та зв’язку з тим самим житлом.
        // EN: The existing offer is updated, preserving its ID and its link to the same property.
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

        // UA: Другий імпорт завершується успішно.
        // EN: The second import completes successfully.
        $this->assertSame(
            ImportStatus::Completed,
            $secondImport->fresh()->status
        );
    }

    // UA: Один і той самий external_id може існувати у різних постачальників незалежно.
    // EN: The same external_id is allowed independently for different suppliers.
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

    // UA: Після успішної обробки імпорт отримує статус completed і коректні метадані.
    // EN: After successful processing, the import is marked completed with correct metadata.
    public function test_it_marks_the_import_as_completed(): void
    {
        $import = $this->createImport([
            $this->offerData(),
            $this->offerData([
                'external_id' => 'offer-a-10002',
            ]),
        ]);

        // UA: Імітуємо попередню невдалу спробу обробки.
        // EN: Simulate a previous failed processing attempt.
        $import->status = ImportStatus::Failed;
        $import->error = 'Previous processing error.';
        $import->save();

        (new ProcessOfferImport($import->id))->handle();

        $import->refresh();

        // UA: Успішна обробка скидає помилку та встановлює completed.
        // EN: Successful processing clears the error and sets completed.
        $this->assertSame(
            ImportStatus::Completed,
            $import->status
        );

        $this->assertEquals(2, $import->total_offers);
        $this->assertEquals(2, $import->processed_offers);
        $this->assertNull($import->error);

        // UA: Метадані часу обробки заповнюються поточним frozen-часом.
        // EN: Processing timestamps are set to the current frozen time.
        $this->assertNotNull($import->started_at);
        $this->assertNotNull($import->completed_at);

        $this->assertTrue($import->started_at->equalTo(now()));
        $this->assertTrue($import->completed_at->equalTo(now()));
    }

    // UA: Повторний запуск Job для вже завершеного імпорту нічого не змінює.
    // EN: Re-running the Job for an already completed import changes nothing.
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

        // UA: Повторний handle() не повинен змінювати дані.
        // EN: A repeated handle() must not alter persisted data.
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

    // UA: Помилка під час обробки другої пропозиції відкочує весь пакет атомарно.
    // EN: A failure while processing the second offer rolls back the entire batch atomically.
    public function test_an_error_on_the_second_offer_rolls_back_the_batch(): void
    {
        // UA: Пакет із двох пропозицій; друга має впасти під час збереження.
        // EN: A two-offer batch where the second fails during persistence.
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

        // UA: Обидві пропозиції намагалися зберегтися перед відкатом.
        // EN: Both offers were attempted before the rollback.
        $this->assertSame(
            ['offer-a-10001', 'offer-a-10002'],
            $attemptedOffers
        );

        // UA: Відкочуються обидва об’єкти житла та перша пропозиція.
        // EN: Both properties and the first offer must be rolled back.
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('offers', 0);

        $import->refresh();

        // UA: Відкат відновлює статус pending і початкові метадані; цей тест не викликає failed().
        // EN: The rollback restores pending status and the original metadata; this test does not call failed().
        $this->assertSame(
            ImportStatus::Pending,
            $import->status
        );
        $this->assertEquals(0, $import->processed_offers);
        $this->assertNull($import->started_at);
        $this->assertNull($import->completed_at);
        $this->assertNull($import->error);
    }

    // UA: Метод failed() зберігає остаточну помилку обробки імпорту.
    // EN: The failed() method persists the import's final processing failure.
    public function test_failed_marks_the_import_as_failed(): void
    {
        $import = $this->createImport([
            $this->offerData(),
        ]);

        // UA: Імітуємо остаточну помилку після вичерпання спроб Job.
        // EN: Simulate a final failure after the Job exhausts its retries.
        $exception = new RuntimeException(
            'Import processing failed after all attempts.'
        );

        // UA: Викликаємо failed() напряму; автоматичні повторні спроби черги тут не перевіряються.
        // EN: Call failed() directly; automatic queue retries are not tested here.
        (new ProcessOfferImport($import->id))->failed($exception);

        $import->refresh();

        // UA: failed() зберігає статус failed і текст помилки.
        // EN: failed() persists the failed status and error message.
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

        // UA: Жодні житло чи пропозиції не повинні з’явитися в базі.
        // EN: No properties or offers must appear in the database.
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('offers', 0);
    }

    // UA: failed() від дубліката Job не перезаписує вже успішно завершений імпорт.
    // EN: failed() from a duplicate Job does not overwrite an already completed import.
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

        // UA: failed() від дубліката Job не повинен змінити completed-імпорт.
        // EN: failed() from a duplicate Job must not alter a completed import.
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
        // UA: Створюємо запис імпорту напряму, без HTTP-шару.
        // EN: Create an import record directly, bypassing the HTTP layer.
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
        // UA: Спільна коректна пропозиція; окремі тести змінюють лише потрібні поля.
        // EN: A shared valid offer; individual tests modify only the relevant fields.
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
