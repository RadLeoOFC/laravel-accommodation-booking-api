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

    // UA: API приймає коректний пакет, зберігає імпорт і надсилає Job у чергу.
    // EN: The API accepts a valid batch, persists the import, and dispatches a Job.
    public function test_it_accepts_an_import_and_dispatches_a_job(): void
    {
        // UA: Надсилаємо коректний пакет від відомого постачальника.
        // EN: Submit a valid batch from a known supplier.
        $payload = $this->validPayload();

        $response = $this->postJson('/api/imports', $payload);

        // UA: Розширений ресурс також коректно працює у відповіді приймання імпорту.
        // EN: The expanded resource also works in the import acceptance response.
        $response
            ->assertJsonPath('data.supplier', $payload['supplier'])
            ->assertJsonPath(
                'data.external_import_id',
                $payload['external_import_id']
            )
            ->assertJsonPath('data.error', null)
            ->assertJsonStructure([
                'data' => ['created_at'],
            ])
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('offer_imports', 1);

        $import = OfferImport::query()->findOrFail(
            $response->json('data.id')
        );

        $supplier = Supplier::query()
            ->where('slug', 'supplier-a')
            ->firstOrFail();

        
        // UA: Перевіряємо постачальника та збережені вхідні дані.
        // EN: Verify the supplier and the persisted input data.
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
        // UA: total_offers відображає кількість надісланих пропозицій.
        // EN: total_offers reflects the number of submitted offers.
        $this->assertEquals(1, $import->total_offers);
        // UA: Обробка ще не почалася: лічильник дорівнює нулю, дати й помилка порожні.
        // EN: Processing has not started: the counter is zero, dates and error are null
        $this->assertEquals(0, $import->processed_offers);

        $this->assertNull($import->started_at);
        $this->assertNull($import->completed_at);
        $this->assertNull($import->error);

        // UA: Надіслано рівно одну Job у чергу imports з правильним ID.
        // EN: Exactly one Job is dispatched to the imports queue with the correct ID.
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

    // UA: Повторний запит з тим самим external_import_id не створює дублікат імпорту.
    // EN: A repeated request with the same external_import_id does not create a duplicate import.
    public function test_repeated_import_does_not_create_duplicates(): void
    {
        // UA: Двічі надсилаємо однаковий пакет з тим самим external_import_id.
        // EN: Submit the same batch twice using the same external_import_id.
        $payload = $this->validPayload();

        $firstResponse = $this->postJson('/api/imports', $payload);
        $firstResponse->assertStatus(202);

        $secondResponse = $this->postJson('/api/imports', $payload);

        // UA: Повторний запит має повернути ID вже створеного імпорту.
        // EN: The repeated request must return the existing import ID.
        $secondResponse
            ->assertStatus(202)
            ->assertJsonPath(
                'data.id',
                $firstResponse->json('data.id')
            );

        $this->assertDatabaseCount('offer_imports', 1);

        Queue::assertPushed(ProcessOfferImport::class, 1);
    }

    // UA: Повторний запит не перезаписує оригінальний payload і sent_at.
    // EN: A repeated request does not overwrite the original payload or sent_at.
    public function test_repeated_import_does_not_overwrite_original_data(): void
    {
        // UA: Спочатку приймаємо початкову версію пакета.
        // EN: First, accept the original batch.
        $original = $this->validPayload();

        $firstResponse = $this->postJson('/api/imports', $original);
        $firstResponse->assertStatus(202);

        // UA: Змінюємо вміст, але залишаємо постачальника та ID імпорту незмінними.
        // EN: Change the contents while keeping the supplier and import ID unchanged.
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

        // UA: Повторний запит не повинен перезаписати payload або sent_at.
        // EN: The repeated request must not overwrite payload or sent_at.
        $this->assertSame($original['offers'], $import->payload);
        $this->assertTrue(
            $import->sent_at->equalTo($original['sent_at'])
        );
        // UA: Зберігається одна записана версія імпорту та одна Job.
        // EN: Only one persisted import and one dispatched Job remain.
        $this->assertDatabaseCount('offer_imports', 1);

        Queue::assertPushed(ProcessOfferImport::class, 1);
    }

    // UA: Один і той самий external_import_id дозволений для різних постачальників.
    // EN: The same external_import_id is allowed for different suppliers.
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

    // UA: Повторно надісланий завершений імпорт не перезапускається і не скидає статус.
    // EN: Resubmitting a completed import does not restart it or reset its status.
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

        // UA: Повтор пакета має повернути поточний статус completed.
        // EN: Resubmitting the batch must return its current completed status.
        $this->postJson('/api/imports', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'completed');

        // UA: Статус не скидається, додаткова Job не створюється.
        // EN: The status is not reset, and no additional Job is dispatched.

        $this->assertSame(
            ImportStatus::Completed,
            $import->fresh()->status
        );

        Queue::assertPushed(ProcessOfferImport::class, 1);
    }

    // UA: Пакет із невідомим постачальником відхиляється валідацією.
    // EN: A batch with an unknown supplier is rejected by validation.
    public function test_unknown_supplier_is_rejected(): void
    {
        // UA: Замінюємо постачальника на код, якого немає в тестовій базі.
        // EN: Replace the supplier with a code absent from the test database.
        $payload = $this->validPayload();
        $payload['supplier'] = 'unknown-supplier';

        // UA: Правило exists має повернути помилку поля supplier.
        // EN: The exists rule must return a validation error for supplier.
        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier']);

        // UA: Відхилений пакет не зберігається і не надсилається в чергу.
        // EN: The rejected batch is neither persisted nor queued.
        $this->assertDatabaseCount('offer_imports', 0);

        Queue::assertNothingPushed();
    }

    // UA: Пропозиція без external_id відхиляється валідацією.
    // EN: An offer without external_id is rejected by validation.
    public function test_missing_external_offer_id_is_rejected(): void
    {
        // UA: Видаляємо обов’язковий external_id з першої пропозиції.
        // EN: Remove the required external_id from the first offer.
        $payload = $this->validPayload();

        unset($payload['offers'][0]['external_id']);

        // UA: Помилка має стосуватися саме вкладеного поля offers.0.external_id.
        // EN: The validation error must identify the nested offers.0.external_id field.
        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offers.0.external_id']);

        // UA: Некоректний пакет не створює імпорт або Job.
        // EN: The invalid batch creates neither an import nor a Job.
        $this->assertDatabaseCount('offer_imports', 0);

        Queue::assertNothingPushed();
    }

    // UA: Пропозиція з некоректними значеннями полів відхиляється валідацією.
    // EN: An offer with invalid field values is rejected by validation.
    public function test_invalid_offer_values_are_rejected(): void
    {
        // UA: Формуємо пропозицію з кількома незалежними помилками.
        // EN: Prepare an offer containing several independent validation errors.
        $payload = $this->validPayload();

        $payload['offers'][0]['property']['code'] = '';
        $payload['offers'][0]['check_out'] = '2026-11-09';
        $payload['offers'][0]['max_guests'] = 0;
        $payload['offers'][0]['price'] = -1;
        $payload['offers'][0]['currency'] = 'EURO';
        $payload['offers'][0]['available_units'] = -1;
        $payload['offers'][0]['expires_at'] = 'not-a-date';

        // UA: Перевіряємо помилки всіх навмисно змінених полів.
        // EN: Verify errors for every deliberately invalid field.
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

        // UA: Валідація має зупинити запит до запису даних або відправлення Job.
        // EN: Validation must stop the request before persisting data or dispatching a Job.
        $this->assertDatabaseCount('offer_imports', 0);
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('offers', 0);

        Queue::assertNothingPushed();
    }

    // UA: Пакет із порожнім масивом offers відхиляється валідацією.
    // EN: A batch with an empty offers array is rejected by validation.
    public function test_empty_offers_array_is_rejected(): void
    {
        // UA: Передаємо пакет без жодної пропозиції.
        // EN: Submit a batch without any offers.
        $payload = $this->validPayload();
        $payload['offers'] = [];

        // UA: За обраним контрактом масив offers повинен бути непорожнім.
        // EN: Under the chosen contract, the offers array must not be empty.
        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offers']);

        // UA: Порожній пакет не створює імпорт або Job.
        // EN: An empty batch creates neither an import nor a Job.
        $this->assertDatabaseCount('offer_imports', 0);

        Queue::assertNothingPushed();
    }

    // UA: Пакет із дублікатами external_id всередині одного запиту відхиляється.
    // EN: A batch containing duplicate external_ids within one request is rejected.
    public function test_duplicate_external_ids_within_a_batch_are_rejected(): void
    {
        // UA: Початковий пакет містить одну коректну пропозицію.
        // EN: The original batch contains one valid offer.
        $payload = $this->validPayload();
        // UA: Додаємо копію першої пропозиції: індекси 0 і 1 мають однаковий external_id.
        // EN: Append a copy of the first offer: indexes 0 and 1 share the same external_id.
        $payload['offers'][] = $payload['offers'][0];

        // UA: Правило distinct:strict має відхилити повтор ID всередині пакета.
        // EN: The distinct:strict rule must reject a repeated ID within the batch.
        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offers.0.external_id']);

        // UA: Пакет із дублікатами відхиляється цілком, без створення імпорту.
        // EN: The duplicate-containing batch is rejected entirely without creating an import.
        $this->assertDatabaseCount('offer_imports', 0);

        Queue::assertNothingPushed();
    }

    private function validPayload(): array
    {
        // UA: Спільний валідний пакет; окремі тести змінюють лише потрібні поля.
        // EN: A shared valid batch; individual tests modify only the relevant fields.
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