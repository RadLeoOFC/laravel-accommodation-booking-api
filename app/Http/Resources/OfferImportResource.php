<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Enums\ImportStatus;
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
            'supplier' => $this->supplier->slug,
            'external_import_id' => $this->external_import_id,
            'created_at' => $this->created_at,
            'status' => $this->status->value,
            'total_offers' => $this->total_offers,
            'processed_offers' => $this->processed_offers,
            'error' => $this->status === ImportStatus::Failed
                ? 'Import processing failed.'
                : null,
            'sent_at' => $this->sent_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
