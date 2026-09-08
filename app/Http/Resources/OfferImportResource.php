<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferImportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'total_offers' => $this->total_offers,
            'processed_offers' => $this->processed_offers,
            'sent_at' => $this->sent_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
