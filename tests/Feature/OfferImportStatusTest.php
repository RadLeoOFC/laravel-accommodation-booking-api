<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\OfferImport;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferImportStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UA: Фіксуємо час для відтворюваності перевірок дат.
        // EN: Freeze time to make timestamp assertions reproducible.
        $this->travelTo(
            Carbon::parse('2026-09-07T12:00:00Z')
        );

        // UA: Перехоплюємо завдання, щоб перевірити відсутність їх відправлення.
        // EN: Capture jobs so we can verify that none are dispatched.
        Queue::fake();

        // UA: Створюємо постачальників у тестовій базі.
        // EN: Create suppliers in the test database.
        $this->seed(SupplierSeeder::class);
    }

    // UA: API повертає імпорт, який очікує на обробку.
    // EN: The API returns an import that is awaiting processing.
    public function test_it_returns_a_pending_import(): void
    {
        // UA: Створюємо імпорт із початковим станом.
        // EN: Create an import in its initial state.
        $import = $this->createImport();

        // UA: Перевіряємо ID, статус, лічильники та часові метадані.
        // EN: Verify the ID, status, counters, and timestamps.
        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_offers', 1)
            ->assertJsonPath('data.processed_offers', 0)
            ->assertJsonPath(
                'data.sent_at',
                $import->sent_at->toJSON()
            )
            ->assertJsonPath('data.started_at', null)
            ->assertJsonPath('data.completed_at', null);
    }

    // UA: API повертає збережений стан processing.
    // EN: The API returns a persisted processing state.
    public function test_it_returns_a_processing_import(): void
    {
        // UA: Зберігаємо стан напряму; видимість транзакції worker тут не перевіряється.
        // EN: Persist the state directly; worker transaction visibility is not tested here.
        $import = $this->createImport([
            'status' => ImportStatus::Processing,
            'started_at' => now()->subMinute(),
        ]);

        // UA: Обробка почалася, але дата завершення ще відсутня.
        // EN: Processing has started, but there is no completion timestamp yet.
        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.total_offers', 1)
            ->assertJsonPath('data.processed_offers', 0)
            ->assertJsonPath(
                'data.sent_at',
                $import->sent_at->toJSON()
            )
            ->assertJsonPath(
                'data.started_at',
                $import->started_at->toJSON()
            )
            ->assertJsonPath('data.completed_at', null);
    }

    // UA: API повертає успішно завершений імпорт із його метаданими.
    // EN: The API returns a successfully completed import with its metadata.
    public function test_it_returns_a_completed_import(): void
    {
        // UA: Готуємо збережений результат успішної обробки.
        // EN: Prepare the persisted result of successful processing.
        $import = $this->createImport([
            'status' => ImportStatus::Completed,
            'processed_offers' => 1,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        // UA: Усі пропозиції оброблені, обидві дати обробки заповнені.
        // EN: All offers are processed, and both processing timestamps are present.
        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_offers', 1)
            ->assertJsonPath('data.processed_offers', 1)
            ->assertJsonPath(
                'data.sent_at',
                $import->sent_at->toJSON()
            )
            ->assertJsonPath(
                'data.started_at',
                $import->started_at->toJSON()
            )
            ->assertJsonPath(
                'data.completed_at',
                $import->completed_at->toJSON()
            );
    }

    // UA: API повертає стан імпорту після остаточної помилки.
    // EN: The API returns the import state after a final failure.
    public function test_it_returns_a_failed_import(): void
    {
        // UA: Відтворюємо стан після відкату пакета та виклику failed().
        // EN: Reproduce the state after batch rollback and a failed() callback.
        $import = $this->createImport([
            'status' => ImportStatus::Failed,
            'error' => 'Import processing failed after all attempts.',
        ]);

        // UA: Імпорт існує, тому GET повертає 200 навіть для статусу failed.
        // EN: The import exists, so GET returns 200 even when its status is failed.
        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.total_offers', 1)
            ->assertJsonPath('data.processed_offers', 0)
            ->assertJsonPath(
                'data.sent_at',
                $import->sent_at->toJSON()
            )
            ->assertJsonPath('data.started_at', null)
            ->assertJsonPath('data.completed_at', null);
    }

    // UA: Запит неіснуючого імпорту повертає 404.
    // EN: Requesting a missing import returns 404.
    public function test_it_returns_404_for_a_missing_import(): void
    {
        // UA: Обираємо ID, більший за всі наявні ID імпортів.
        // EN: Choose an ID greater than all existing import IDs.
        $missingId = ((int) OfferImport::query()->max('id')) + 1;

        // UA: Прив’язка моделі до маршруту повинна відхилити відсутній запис.
        // EN: Route model binding must reject the missing record.
        $this->getJson("/api/imports/{$missingId}")
            ->assertNotFound();
    }

    // UA: API не розкриває вхідний пакет або внутрішню технічну помилку.
    // EN: The API does not expose the incoming payload or internal error.
    public function test_it_does_not_expose_payload_or_internal_error(): void
    {
        // UA: Обидва поля заповнені, щоб перевірка їх приховування була змістовною.
        // EN: Populate both fields to make the omission check meaningful.
        $import = $this->createImport([
            'status' => ImportStatus::Failed,
            'error' => 'Internal database error: diagnostic details.',
        ]);

        $this->assertNotEmpty($import->payload);
        $this->assertNotEmpty($import->error);

        // UA: Публічна відповідь містить статус, але не діагностичні дані.
        // EN: The public response includes the status but omits diagnostic data.
        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonMissingPath('data.payload')
            ->assertJsonMissingPath('data.error');
    }

    // UA: Читання стану не змінює імпорт і не відправляє завдання.
    // EN: Reading the status neither changes the import nor dispatches jobs.
    public function test_it_does_not_modify_the_import_or_dispatch_jobs(): void
    {
        $import = $this->createImport();

        // UA: Зберігаємо всі атрибути з бази, включно з updated_at.
        // EN: Capture all persisted attributes, including updated_at.
        $before = $import->fresh()->getRawOriginal();

        // UA: Зсуваємо час, щоб виявити небажане оновлення часової мітки.
        // EN: Advance time to detect an unintended timestamp update.
        $this->travelTo(
            Carbon::parse('2026-09-07T13:00:00Z')
        );

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'pending');

        // UA: Запис залишається незмінним; нові імпорти та пропозиції не створюються.
        // EN: The record remains unchanged; no new imports or offers are created.
        $this->assertSame(
            $before,
            $import->fresh()->getRawOriginal()
        );

        $this->assertDatabaseCount('offer_imports', 1);
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('offers', 0);

        // UA: GET не повинен запускати або повторно запускати обробку.
        // EN: GET must not start or restart processing.
        Queue::assertNothingPushed();
    }

    private function createImport(array $overrides = []): OfferImport
    {
        // UA: Знаходимо постачальника, створеного в setUp().
        // EN: Find the supplier created in setUp().
        $supplier = Supplier::query()
            ->where('slug', 'supplier-a')
            ->firstOrFail();

        // UA: Готуємо одну пропозицію без запуску Job.
        // EN: Prepare one offer without running a job.
        $offers = [
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
        ];

        // UA: Тест перевизначає лише поля, потрібні для свого сценарію.
        // EN: Each test overrides only the fields needed for its scenario.
        return OfferImport::query()->create(array_replace([
            'supplier_id' => $supplier->id,
            'external_import_id' => 'test-' . Str::uuid(),
            'sent_at' => '2026-09-07T10:00:00Z',
            'status' => ImportStatus::Pending,
            'payload' => $offers,
            'total_offers' => count($offers),
            'processed_offers' => 0,
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
        ], $overrides));
    }
}