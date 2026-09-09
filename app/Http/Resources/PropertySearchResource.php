<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class PropertySearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // UA: Основні дані об’єкта житла.
            // EN: Basic property information.
            'code' => $this->code,
            'name' => $this->name,
            'city' => $this->city,

            // UA: Найкраща актуальна пропозиція, обрана SQL-запитом.
            // EN: The best available offer selected by the SQL query.
            'best_offer' => [
                'id' => (int) $this->best_offer_id,
                'supplier' => $this->best_offer_supplier,

                // UA: Повертаємо початкову ціну та валюту, зберігаючи точність DECIMAL.
                // EN: Return the original price and currency, preserving DECIMAL precision.
                'price' => (string) $this->best_offer_price,
                'currency' => $this->best_offer_currency,
                'available_units' => (int) $this->best_offer_available_units,

                // UA: Query Builder повертає дату рядком; перетворюємо її у формат JSON UTC.
                // EN: Query Builder returns the date as a string; convert it to UTC JSON format.
                'expires_at' => Carbon::parse(
                    $this->best_offer_expires_at,
                    'UTC'
                )->toJSON(),
            ],
        ];
    }
}