<?php

namespace App\Queries;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

class PropertySearchQuery
{
    /**
     * UA: Параметри мають пройти перевірку в SearchPropertiesRequest.
     * EN: Parameters must be validated by SearchPropertiesRequest.
     *
     * @param array{
     *     check_in: string,
     *     check_out: string,
     *     guests: int|string,
     *     city?: string|null,
     *     page?: int|string,
     *     per_page?: int|string
     * } $filters
     */
    public function paginate(array $filters): Paginator
    {
        // UA: Використовуємо один момент часу для перевірки актуальності.
        // EN: Use a single point in time to check offer validity.
        $now = now();

        // UA: Відбираємо пропозиції з відповідними датами, місткістю та залишком.
        // EN: Select offers with matching dates, sufficient capacity, and stock.
        $rankedOffers = DB::table('offers')
            ->join(
                'properties',
                'properties.id',
                '=',
                'offers.property_id'
            )
            ->join(
                'exchange_rates',
                'exchange_rates.currency',
                '=',
                'offers.currency'
            )
            ->where('offers.check_in', $filters['check_in'])
            ->where('offers.check_out', $filters['check_out'])
            ->where('offers.max_guests', '>=', (int) $filters['guests'])
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', $now)
            ->where('exchange_rates.rate_to_usd', '>', 0);

        // UA: Фільтруємо місто лише тоді, коли його передано.
        // EN: Filter by city only when it is provided.
        if (isset($filters['city']) && $filters['city'] !== '') {
            $rankedOffers->where('properties.city', $filters['city']);
        }

        // UA: Зберігаємо початкову ціну; USD використовуємо для порівняння.
        // EN: Preserve the original price; use USD for comparison.
        $rankedOffers
            ->select([
                'properties.id as property_id',
                'properties.code',
                'properties.name',
                'properties.city',
                'offers.id as offer_id',
                'offers.supplier_id',
                'offers.price',
                'offers.currency',
                'offers.available_units',
                'offers.expires_at',
            ])
            ->selectRaw(
                'offers.price * exchange_rates.rate_to_usd AS price_usd'
            )
            // UA: За однакової ціни в USD обираємо пропозицію з меншим ID.
            // EN: For equal USD prices, prefer the offer with the smaller ID.
            ->selectRaw('
                ROW_NUMBER() OVER (
                    PARTITION BY offers.property_id
                    ORDER BY
                        offers.price * exchange_rates.rate_to_usd ASC,
                        offers.id ASC
                ) AS offer_rank
            ');

        // UA: Залишаємо одну найкращу пропозицію для кожного об’єкта житла.
        // EN: Keep one best offer for each property.
        $query = DB::query()
            ->fromSub($rankedOffers, 'ranked_offers')
            ->join(
                'suppliers',
                'suppliers.id',
                '=',
                'ranked_offers.supplier_id'
            )
            ->where('ranked_offers.offer_rank', 1)
            ->select([
                'ranked_offers.property_id as id',
                'ranked_offers.code',
                'ranked_offers.name',
                'ranked_offers.city',
                'ranked_offers.offer_id as best_offer_id',
                'suppliers.slug as best_offer_supplier',
                'ranked_offers.price as best_offer_price',
                'ranked_offers.currency as best_offer_currency',
                'ranked_offers.available_units as best_offer_available_units',
                'ranked_offers.expires_at as best_offer_expires_at',
            ])
            // UA: Сортуємо за ціною в USD, потім за ID житла для однозначного порядку.
            // EN: Sort by USD price, then by property ID for deterministic ordering.
            ->orderBy('ranked_offers.price_usd')
            ->orderBy('ranked_offers.property_id');

        // UA: Пагінація виконується в SQL після вибору найкращих пропозицій.
        // EN: Paginate in SQL after selecting the best offers.
        return $query->simplePaginate(
            perPage: (int) ($filters['per_page'] ?? 15),
            columns: ['*'],
            pageName: 'page',
            page: (int) ($filters['page'] ?? 1),
        );
    }
}