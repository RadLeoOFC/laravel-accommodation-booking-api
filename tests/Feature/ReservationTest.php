<?php

namespace Tests\Feature;

use App\Actions\CreateReservation;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UA: Фіксуємо час для перевірок актуальності пропозицій.
        // EN: Freeze time to test offer validity.
        $this->travelTo(
            Carbon::parse('2026-09-07T12:00:00Z')
        );

        $this->seed(SupplierSeeder::class);
    }

    // UA: Коректний запит створює бронь і списує одну одиницю.
    // EN: A valid request creates a reservation and deducts one unit.
    public function test_it_creates_a_reservation_and_decrements_stock(): void
    {
        $offer = $this->createOffer();
        $payload = $this->validPayload();

        $response = $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $payload
        );

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'data' => ['id'],
            ])
            ->assertJsonPath('data.offer_id', $offer->id)
            ->assertJsonPath(
                'data.client_reference',
                $payload['client_reference']
            )
            ->assertJsonPath('data.customer_name', $payload['customer_name'])
            ->assertJsonPath('data.customer_email', $payload['customer_email']);

        // UA: Бронь містить контакти та умови обраної пропозиції.
        // EN: The reservation contains the contacts and selected offer's terms.
        $this->assertDatabaseHas('reservations', [
            'id' => $response->json('data.id'),
            'offer_id' => $offer->id,
            'client_reference' => $payload['client_reference'],
            'customer_name' => $payload['customer_name'],
            'customer_email' => $payload['customer_email'],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'price' => '100.00',
            'currency' => 'EUR',
        ]);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(1, $offer->fresh()->available_units);
    }

    // UA: Пропозицію з нульовим залишком забронювати неможливо.
    // EN: An offer with zero stock cannot be reserved.
    public function test_it_rejects_an_offer_without_available_units(): void
    {
        $offer = $this->createOffer(['available_units' => 0]);

        $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $this->validPayload()
        )->assertStatus(409);

        $this->assertDatabaseCount('reservations', 0);
        $this->assertEquals(0, $offer->fresh()->available_units);
    }

    // UA: Пропозиція недоступна після завершення строку дії або без цього строку.
    // EN: An offer is unavailable after expiration or without an expiration time.
    public function test_it_rejects_expired_or_undated_offers(): void
    {
        // UA: Перевіряємо минулий час, точну межу now() та null.
        // EN: Check a past timestamp, the exact now() boundary, and null.
        foreach ([
            now()->subSecond()->toJSON(),
            now()->toJSON(),
            null,
        ] as $expiresAt) {
            $offer = $this->createOffer([
                'expires_at' => $expiresAt,
            ]);

            $this->postJson(
                "/api/offers/{$offer->id}/reservations",
                $this->validPayload()
            )->assertStatus(409);

            $this->assertEquals(2, $offer->fresh()->available_units);
        }

        $this->assertDatabaseCount('reservations', 0);
    }

    // UA: Після бронювання останньої одиниці інше замовлення відхиляється.
    // EN: After the last unit is booked, another order is rejected.
    public function test_it_does_not_reserve_the_last_unit_twice(): void
    {
        $offer = $this->createOffer(['available_units' => 1]);

        $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $this->validPayload()
        )->assertCreated();

        $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $this->validPayload([
                'client_reference' => 'web-order-second',
            ])
        )->assertStatus(409);

        // UA: Це послідовний тест залишку, а не тест паралельних процесів.
        // EN: This is a sequential stock test, not a parallel-process test.
        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(0, $offer->fresh()->available_units);
    }

    // UA: Точний повтор повертає ту саму бронь без додаткового списання.
    // EN: An exact retry returns the same reservation without another deduction.
    public function test_repeated_request_returns_the_existing_reservation(): void
    {
        $offer = $this->createOffer();
        $payload = $this->validPayload();

        $firstResponse = $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $payload
        )->assertCreated();

        $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $payload
        )
            ->assertOk()
            ->assertJsonPath('data.id', $firstResponse->json('data.id'));

        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(1, $offer->fresh()->available_units);
    }

    // UA: Повтор успішного замовлення працює навіть після вичерпання залишку та строку дії.
    // EN: A successful order can be retried even after stock exhaustion and expiration.
    public function test_retry_returns_the_reservation_after_offer_expiration(): void
    {
        $offer = $this->createOffer(['available_units' => 1]);
        $payload = $this->validPayload();

        $firstResponse = $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $payload
        )->assertCreated();

        $this->travelTo(
            Carbon::parse('2026-09-11T12:00:00Z')
        );

        $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $payload
        )
            ->assertOk()
            ->assertJsonPath('data.id', $firstResponse->json('data.id'));

        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(0, $offer->fresh()->available_units);
    }

    // UA: Один client_reference не можна використати для іншої пропозиції.
    // EN: One client_reference cannot be reused for another offer.
    public function test_it_rejects_reference_reuse_for_another_offer(): void
    {
        $firstOffer = $this->createOffer();
        $secondOffer = $this->createOffer();
        $payload = $this->validPayload();

        $this->postJson(
            "/api/offers/{$firstOffer->id}/reservations",
            $payload
        )->assertCreated();

        $this->postJson(
            "/api/offers/{$secondOffer->id}/reservations",
            $payload
        )->assertStatus(409);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(1, $firstOffer->fresh()->available_units);
        $this->assertEquals(2, $secondOffer->fresh()->available_units);
    }

    // UA: Повтор з іншими контактними даними вважається конфліктом.
    // EN: A retry with different contact details is treated as a conflict.
    public function test_it_rejects_reference_reuse_with_different_contacts(): void
    {
        $offer = $this->createOffer();
        $payload = $this->validPayload();

        $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $payload
        )->assertCreated();

        foreach ([
            ['customer_name' => 'Jane Smith'],
            ['customer_email' => 'jane@example.com'],
        ] as $changes) {
            $this->postJson(
                "/api/offers/{$offer->id}/reservations",
                array_replace($payload, $changes)
            )->assertStatus(409);
        }

        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(1, $offer->fresh()->available_units);

        // UA: Початкові контакти не перезаписуються.
        // EN: The original contact details are not overwritten.
        $this->assertDatabaseHas('reservations', [
            'client_reference' => $payload['client_reference'],
            'customer_name' => $payload['customer_name'],
            'customer_email' => $payload['customer_email'],
        ]);
    }

    // UA: Неіснуюча пропозиція повертає 404.
    // EN: A missing offer returns 404.
    public function test_it_returns_404_for_a_missing_offer(): void
    {
        $missingId = ((int) Offer::query()->max('id')) + 1;

        $this->postJson(
            "/api/offers/{$missingId}/reservations",
            $this->validPayload()
        )->assertNotFound();

        $this->assertDatabaseCount('reservations', 0);
    }

    // UA: Некоректні контактні дані та відсутні поля відхиляються до списання.
    // EN: Invalid contact details and missing fields are rejected before deduction.
    public function test_it_rejects_invalid_request_data(): void
    {
        $offer = $this->createOffer();
        $url = "/api/offers/{$offer->id}/reservations";

        $this->postJson($url, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'client_reference',
                'customer_name',
                'customer_email',
            ]);

        foreach ([
            ['client_reference', str_repeat('a', 256)],
            ['customer_name', str_repeat('a', 256)],
            ['customer_email', 'not-an-email'],
        ] as [$field, $value]) {
            $this->postJson(
                $url,
                $this->validPayload([$field => $value])
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseCount('reservations', 0);
        $this->assertEquals(2, $offer->fresh()->available_units);
    }

    // UA: Зміна пропозиції не змінює збережені умови броні та її API-відповідь.
    // EN: Changing an offer does not alter the stored booking terms or its API response.
    public function test_it_preserves_the_reservation_snapshot(): void
    {
        $offer = $this->createOffer();
        $payload = $this->validPayload();

        // UA: Перший запит створює бронь і повертає 201.
        // EN: The first request creates a reservation and returns 201.
        $response = $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $payload
        )->assertCreated();

        $reservation = Reservation::query()
            ->findOrFail($response->json('data.id'));

        $before = $reservation->getRawOriginal();

        // UA: Імітуємо оновлення умов пропозиції новим імпортом.
        // EN: Simulate an offer terms update by a later import.
        $offer->update([
            'check_in' => '2026-11-10',
            'check_out' => '2026-11-15',
            'price' => '200.00',
            'currency' => 'USD',
        ]);

        // UA: У базі залишаються початкові умови бронювання.
        // EN: The database retains the original booking terms.
        $this->assertSame(
            $before,
            $reservation->fresh()->getRawOriginal()
        );

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'price' => '100.00',
            'currency' => 'EUR',
        ]);

        // UA: Повторний запит повертає наявну бронь із початковими умовами та статусом 200.
        // EN: A repeated request returns the existing reservation with its original terms and status 200.
        $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $payload
        )
            ->assertOk()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.check_in', '2026-10-10')
            ->assertJsonPath('data.check_out', '2026-10-15')
            ->assertJsonPath('data.price', '100.00')
            ->assertJsonPath('data.currency', 'EUR');

        // UA: Повтор не створює додаткову бронь і не списує ще одну одиницю.
        // EN: The retry neither creates another reservation nor deducts another unit.
        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(1, $offer->fresh()->available_units);
    }

    // UA: Помилка збереження броні відкочує списання залишку.
    // EN: A reservation persistence failure rolls back the stock deduction.
    public function test_it_rolls_back_stock_when_reservation_creation_fails(): void
    {
        $offer = $this->createOffer();
        $before = $offer->fresh()->getRawOriginal();
        $caughtException = null;

        // UA: Ізолюємо тимчасовий обробник події від інших тестів.
        // EN: Isolate the temporary event listener from other tests.
        $originalDispatcher = Reservation::getEventDispatcher();
        Reservation::setEventDispatcher(clone $originalDispatcher);

        try {
            Reservation::creating(function (Reservation $reservation) use ($offer): void {
                // UA: Переконуємося, що списання вже відбулося всередині транзакції.
                // EN: Verify that deduction has already happened inside the transaction.
                $this->assertDatabaseHas('offers', [
                    'id' => $offer->id,
                    'available_units' => 1,
                ]);

                throw new RuntimeException(
                    'Simulated reservation persistence failure.'
                );
            });

            try {
                // UA: Викликаємо дію напряму для перевірки транзакційного відкату.
                // EN: Call the action directly to test transaction rollback.
                app(CreateReservation::class)->handle(
                    $offer->id,
                    $this->validPayload()
                );
            } catch (RuntimeException $exception) {
                $caughtException = $exception;
            }
        } finally {
            Reservation::setEventDispatcher($originalDispatcher);
        }

        $this->assertInstanceOf(
            RuntimeException::class,
            $caughtException
        );

        $this->assertSame(
            'Simulated reservation persistence failure.',
            $caughtException->getMessage()
        );

        // UA: Бронь не створено, а всі атрибути пропозиції відновлено.
        // EN: No reservation is created, and all offer attributes are restored.
        $this->assertDatabaseCount('reservations', 0);

        $this->assertSame(
            $before,
            $offer->fresh()->getRawOriginal()
        );
    }

    private function createOffer(array $overrides = []): Offer
    {
        $supplier = Supplier::query()
            ->where('slug', 'supplier-a')
            ->firstOrFail();

        // UA: Фабрика створює житло та узгоджений імпорт для пропозиції.
        // EN: The factory creates a property and a consistent import for the offer.
        return Offer::factory()
            ->for($supplier, 'supplier')
            ->create(array_replace([
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 4,
                'price' => '100.00',
                'currency' => 'EUR',
                'available_units' => 2,
                'expires_at' => '2026-09-10T23:59:59Z',
            ], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        // UA: Спільний коректний запит; тест змінює лише потрібні поля.
        // EN: A shared valid request; each test changes only relevant fields.
        return array_replace([
            'client_reference' => 'web-order-9f782b1c',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ], $overrides);
    }
}