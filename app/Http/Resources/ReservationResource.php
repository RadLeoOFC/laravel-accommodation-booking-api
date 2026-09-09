<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'offer_id' => (int) $this->offer_id,
            'client_reference' => $this->client_reference,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,

            // UA: Повертаємо умови, зафіксовані в бронюванні.
            // EN: Return the terms recorded in the reservation.
            'check_in' => $this->check_in->toDateString(),
            'check_out' => $this->check_out->toDateString(),
            'price' => $this->price,
            'currency' => $this->currency,

            'created_at' => $this->created_at,
        ];
    }
}