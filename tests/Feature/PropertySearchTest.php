<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PropertySearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UA: Фіксуємо час для перевірки строку дії пропозицій.
        // EN: Freeze time to test offer expiration.
        $this->travelTo(
            Carbon::parse('2026-09-07T12:00:00Z')
        );

        // UA: Створюємо двох постачальників, передбачених завданням.
        // EN: Create the two suppliers required by the assignment.
        $this->seed(SupplierSeeder::class);

        // UA: Фіксуємо тестові курси, щоб результати не залежали від зовнішніх сервісів.
        // EN: Fix test exchange rates so results do not depend on external services.
        foreach ([
            'USD' => '1.0000000000',
            'EUR' => '1.1000000000',
        ] as $currency => $rate) {
            DB::table('exchange_rates')->updateOrInsert(
                ['currency' => $currency],
                [
                    'rate_to_usd' => $rate,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    // UA: API повертає житло з обраною пропозицією та метаданими пагінації.
    // EN: The API returns a property with its selected offer and pagination metadata.
    public function test_it_returns_a_property_with_its_best_offer(): void
    {
        $property = $this->createProperty();
        $offer = $this->createOffer($property);

        // UA: Перевіряємо дані житла та початкові ціну й валюту пропозиції.
        // EN: Verify property details and the offer's original price and currency.
        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $property->code)
            ->assertJsonPath('data.0.name', $property->name)
            ->assertJsonPath('data.0.city', 'Barcelona')
            ->assertJsonPath('data.0.best_offer.id', $offer->id)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-a')
            ->assertJsonPath('data.0.best_offer.price', '100.00')
            ->assertJsonPath('data.0.best_offer.currency', 'EUR')
            ->assertJsonPath('data.0.best_offer.available_units', 2)
            ->assertJsonPath(
                'data.0.best_offer.expires_at',
                $offer->expires_at->toJSON()
            )
            ->assertJsonStructure([
                'links' => ['next', 'prev'],
                'meta' => ['per_page'],
            ])
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('links.next', null)
            ->assertJsonPath('links.prev', null);
    }

    // UA: Для одного житла повертається лише найдешевша відповідна пропозиція.
    // EN: Only the cheapest matching offer is returned for one property.
    public function test_it_selects_the_cheapest_offer_without_duplicate_properties(): void
    {
        $property = $this->createProperty();

        $this->createOffer($property, ['price' => '150.00']);

        $cheapest = $this->createOffer(
            $property,
            ['price' => '90.00'],
            'supplier-b'
        );

        $this->createOffer($property, ['price' => '120.00']);

        // UA: Три пропозиції різних цін дають один об’єкт у відповіді.
        // EN: Three differently priced offers produce one property in the response.
        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $cheapest->id)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b')
            ->assertJsonPath('data.0.best_offer.price', '90.00');
    }

    // UA: Порівняння пропозицій враховує конвертацію в USD.
    // EN: Offer comparison accounts for conversion to USD.
    public function test_it_compares_offers_in_usd(): void
    {
        $property = $this->createProperty();

        // UA: За тестовим курсом 100 EUR = 110 USD.
        // EN: At the test rate, 100 EUR equals 110 USD.
        $this->createOffer($property, [
            'price' => '100.00',
            'currency' => 'EUR',
        ]);

        $best = $this->createOffer(
            $property,
            [
                'price' => '105.00',
                'currency' => 'USD',
            ],
            'supplier-b'
        );

        // UA: 105 USD дешевше, хоча початкова сума 100 EUR чисельно менша.
        // EN: 105 USD is cheaper even though the original EUR amount is numerically smaller.
        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $best->id)
            ->assertJsonPath('data.0.best_offer.price', '105.00')
            ->assertJsonPath('data.0.best_offer.currency', 'USD');
    }

    // UA: За однакової ціни в USD обирається пропозиція з меншим ID.
    // EN: Equal USD prices are resolved using the smaller offer ID.
    public function test_it_breaks_equal_price_ties_by_offer_id(): void
    {
        $property = $this->createProperty();

        $first = $this->createOffer($property, [
            'price' => '100.00',
            'currency' => 'EUR',
        ]);

        $this->createOffer(
            $property,
            [
                'price' => '110.00',
                'currency' => 'USD',
            ],
            'supplier-b'
        );

        // UA: Обидві пропозиції коштують 110 USD; перша має менший ID.
        // EN: Both offers cost 110 USD; the first has the smaller ID.
        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $first->id);
    }

    // UA: Дати заїзду та виїзду мають точно відповідати запиту.
    // EN: Check-in and check-out dates must exactly match the request.
    public function test_it_requires_exact_check_in_and_check_out_dates(): void
    {
        $property = $this->createProperty();

        $this->createOffer($property, [
            'price' => '10.00',
            'check_in' => '2026-10-09',
        ]);

        $this->createOffer($property, [
            'price' => '20.00',
            'check_out' => '2026-10-16',
        ]);

        $matching = $this->createOffer($property);

        // UA: Дешевші пропозиції з іншими датами не беруть участі у виборі.
        // EN: Cheaper offers with different dates are excluded from selection.
        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $matching->id);
    }

    // UA: Пропозиція повинна вміщувати запитану кількість гостей.
    // EN: An offer must accommodate the requested number of guests.
    public function test_it_requires_sufficient_guest_capacity(): void
    {
        $property = $this->createProperty();

        $this->createOffer($property, [
            'price' => '10.00',
            'max_guests' => 1,
        ]);

        $matching = $this->createOffer($property, [
            'max_guests' => 2,
        ]);

        // UA: Рівність max_guests і guests допускається.
        // EN: Equal max_guests and guests values are allowed.
        $this->getJson($this->searchUrl(['guests' => 2]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $matching->id);
    }

    // UA: Недоступні та прострочені пропозиції виключаються до вибору найкращої.
    // EN: Unavailable and expired offers are excluded before selecting the best one.
    public function test_it_excludes_unavailable_and_expired_offers(): void
    {
        $property = $this->createProperty();

        $this->createOffer($property, [
            'price' => '10.00',
            'available_units' => 0,
        ]);

        $this->createOffer($property, [
            'price' => '20.00',
            'expires_at' => now()->subSecond()->toJSON(),
        ]);

        // UA: Пропозиція, що спливає рівно зараз, уже неактуальна.
        // EN: An offer expiring exactly now is no longer valid.
        $this->createOffer($property, [
            'price' => '30.00',
            'expires_at' => now()->toJSON(),
        ]);

        $matching = $this->createOffer($property, [
            'available_units' => 1,
            'expires_at' => now()->addSecond()->toJSON(),
        ]);

        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $matching->id);
    }

    // UA: Переданий city обмежує пошук указаним містом.
    // EN: A supplied city restricts the search to that city.
    public function test_it_filters_properties_by_city(): void
    {
        $barcelona = $this->createProperty(['city' => 'Barcelona']);
        $madrid = $this->createProperty(['city' => 'Madrid']);

        $this->createOffer($barcelona);
        $this->createOffer($madrid, ['price' => '10.00']);

        $this->getJson($this->searchUrl(['city' => 'Barcelona']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $barcelona->code);

        // UA: Невідоме місто дає порожній результат без помилки валідації.
        // EN: An unknown city produces an empty result without a validation error.
        $this->getJson($this->searchUrl(['city' => 'Unknown City']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // UA: За відсутності city пошук охоплює всі міста.
    // EN: When city is omitted, the search includes all cities.
    public function test_it_searches_all_cities_when_city_is_omitted(): void
    {
        $barcelona = $this->createProperty(['city' => 'Barcelona']);
        $madrid = $this->createProperty(['city' => 'Madrid']);

        $this->createOffer($barcelona, ['price' => '100.00']);
        $this->createOffer($madrid, ['price' => '120.00']);

        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', $barcelona->code)
            ->assertJsonPath('data.1.code', $madrid->code);
    }

    // UA: Житло сортується за ціною в USD і розподіляється між сторінками без дублікатів.
    // EN: Properties are sorted by USD price and paginated without duplicates.
    public function test_it_sorts_by_usd_price_and_paginates_properties(): void
    {
        $eurProperty = $this->createProperty();
        $usdProperty = $this->createProperty();
        $expensiveProperty = $this->createProperty();

        // UA: Очікуваний порядок у USD: 105, 110, 130.
        // EN: The expected USD price order is 105, 110, 130.
        $this->createOffer($eurProperty, [
            'price' => '100.00',
            'currency' => 'EUR',
        ]);

        // UA: Друга пропозиція того самого житла не повинна зайняти місце на сторінці.
        // EN: A second offer for the same property must not occupy another page slot.
        $this->createOffer($eurProperty, [
            'price' => '200.00',
            'currency' => 'EUR',
        ]);

        $this->createOffer($usdProperty, [
            'price' => '105.00',
            'currency' => 'USD',
        ]);

        $this->createOffer($expensiveProperty, [
            'price' => '130.00',
            'currency' => 'USD',
        ]);

        $firstPage = $this->getJson($this->searchUrl([
            'city' => 'Barcelona',
            'per_page' => 2,
        ]));

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', $usdProperty->code)
            ->assertJsonPath('data.1.code', $eurProperty->code)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('links.prev', null);

        // UA: Посилання next повинно зберігати всі параметри пошуку.
        // EN: The next link must preserve all search parameters.
        $nextUrl = $firstPage->json('links.next');
        $this->assertIsString($nextUrl);

        parse_str(
            (string) parse_url($nextUrl, PHP_URL_QUERY),
            $nextQuery
        );

        $this->assertSame('2', $nextQuery['page']);
        $this->assertSame('2', $nextQuery['per_page']);
        $this->assertSame('Barcelona', $nextQuery['city']);
        $this->assertSame('2026-10-10', $nextQuery['check_in']);
        $this->assertSame('2026-10-15', $nextQuery['check_out']);
        $this->assertSame('2', $nextQuery['guests']);

        // UA: Переходимо за посиланням API на останню сторінку.
        // EN: Follow the API link to the final page.
        $secondPage = $this->getJson($nextUrl);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $expensiveProperty->code)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('links.next', null);

        $this->assertIsString($secondPage->json('links.prev'));
    }

    // UA: За рівних цін порядок житла визначається його ID.
    // EN: Property IDs determine ordering when prices are equal.
    public function test_it_breaks_property_sorting_ties_by_property_id(): void
    {
        $first = $this->createProperty();
        $second = $this->createProperty();

        // UA: Створюємо пропозиції у зворотному порядку відносно житла.
        // EN: Create offers in the reverse order of their properties.
        $this->createOffer($second);
        $this->createOffer($first);

        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', $first->code)
            ->assertJsonPath('data.1.code', $second->code);
    }

    // UA: Житло без відповідних пропозицій не потрапляє у видачу.
    // EN: Properties without matching offers are omitted from results.
    public function test_it_returns_an_empty_result_when_no_offers_match(): void
    {
        // UA: Один об’єкт не має пропозицій, інший має лише недоступну.
        // EN: One property has no offers; another has only an unavailable offer.
        $this->createProperty();

        $unavailable = $this->createProperty();
        $this->createOffer($unavailable, ['available_units' => 0]);

        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('links.next', null)
            ->assertJsonPath('links.prev', null)
            ->assertJsonPath('meta.per_page', 15);
    }

    // UA: За обраним правилом пропозиції без додатного курсу виключаються.
    // EN: Under the chosen policy, offers without a positive exchange rate are excluded.
    public function test_it_excludes_offers_without_a_positive_exchange_rate(): void
    {
        $property = $this->createProperty();

        // UA: Для GBP курс відсутній.
        // EN: GBP has no exchange rate.
        $this->createOffer($property, [
            'price' => '1.00',
            'currency' => 'GBP',
        ]);

        // UA: Нульовий курс EUR не повинен перетворити пропозицію на безкоштовну.
        // EN: A zero EUR rate must not make an offer appear free.
        DB::table('exchange_rates')
            ->where('currency', 'EUR')
            ->update(['rate_to_usd' => '0']);

        $this->createOffer($property, [
            'price' => '2.00',
            'currency' => 'EUR',
        ]);

        $matching = $this->createOffer($property, [
            'price' => '100.00',
            'currency' => 'USD',
        ]);

        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $matching->id);
    }

    // UA: Запит без обов’язкових параметрів відхиляється валідацією.
    // EN: A request without required parameters is rejected by validation.
    public function test_it_rejects_missing_required_search_parameters(): void
    {
        $this->getJson('/api/properties')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'check_in',
                'check_out',
                'guests',
            ]);
    }

    // UA: Некоректні значення фільтрів і пагінації відхиляються.
    // EN: Invalid filter and pagination values are rejected.
    public function test_it_rejects_invalid_search_parameters(): void
    {
        // UA: Кожен запит змінює один параметр коректного пошуку.
        // EN: Each request changes one parameter of an otherwise valid search.
        $cases = [
            ['check_in', 'not-a-date'],
            ['check_out', 'not-a-date'],
            ['check_out', '2026-10-10'],
            ['check_out', '2026-10-09'],
            ['guests', 0],
            ['guests', 1.5],
            ['city', ['Barcelona']],
            ['city', str_repeat('a', 121)],
            ['page', 0],
            ['page', 1.5],
            ['per_page', 0],
            ['per_page', 101],
        ];

        foreach ($cases as [$field, $value]) {
            $this->getJson($this->searchUrl([$field => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    private function searchUrl(array $overrides = []): string
    {
        // UA: Формуємо URL зі спільними коректними параметрами.
        // EN: Build a URL with shared valid search parameters.
        $parameters = array_replace([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ], $overrides);

        return '/api/properties?' . http_build_query($parameters);
    }

    private function createProperty(array $overrides = []): Property
    {
        // UA: Створюємо житло через фабрику з потрібними для тесту значеннями.
        // EN: Create a property through its factory with test-specific values.
        return Property::factory()->create(array_replace([
            'name' => 'Test Apartment',
            'city' => 'Barcelona',
        ], $overrides));
    }

    private function createOffer(
        Property $property,
        array $overrides = [],
        string $supplierSlug = 'supplier-a'
    ): Offer {
        $supplier = Supplier::query()
            ->where('slug', $supplierSlug)
            ->firstOrFail();

        // UA: Передаємо фабриці наявне житло та постачальника із сідера.
        // EN: Pass the existing property and seeded supplier to the factory.
        // UA: OfferFactory створить узгоджений імпорт без запуску Job.
        // EN: OfferFactory creates a consistent import without running a job.
        return Offer::factory()
            ->for($property, 'property')
            ->for($supplier, 'supplier')
            ->create(array_replace([
                // UA: Значення, що впливають на пошук, задаємо явно.
                // EN: Set values affecting search explicitly.
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 4,
                'price' => '100.00',
                'currency' => 'EUR',
                'available_units' => 2,
                'expires_at' => '2026-09-10T23:59:59Z',
            ], $overrides));
    }
}