<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\OfferImport;
use App\Models\Offer;
use App\Models\Property;
use App\Enums\ImportStatus;
use Throwable;
use Illuminate\Support\Facades\DB;

class ProcessOfferImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $importId)
    {
        
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // UA: Усі зміни пакета зберігаються разом або повністю відкочуються.
        // EN: All batch changes are committed together or rolled back entirely.
        DB::transaction(function (): void {
            // UA: Блокуємо імпорт до завершення транзакції.
            // EN: Lock the import until the transaction finishes.
            $import = OfferImport::query()
                ->lockForUpdate()
                ->findOrFail($this->importId);

            // UA: Повторна Job не повинна обробляти завершений імпорт.
            // EN: A duplicate Job must not process a completed import again.
            if ($import->status === ImportStatus::Completed) {
                return;
            }

            $import->update([
                'status' => ImportStatus::Processing,
                'started_at' => now(),
                'completed_at' => null,
                'processed_offers' => 0,
                'error' => null,
            ]);

            $processedOffers = 0;

            foreach ($import->payload as $offerData) {
                // UA: Знаходимо житло за кодом або створюємо новий об’єкт.
                // EN: Find the property by code or create a new one.
                $property = Property::query()->firstOrCreate(
                    [
                        'code' => $offerData['property']['code'],
                    ],
                    [
                        'name' => $offerData['property']['name'],
                        'city' => $offerData['property']['city'],
                    ],
                );

                // UA: Ідентифікатор пропозиції унікальний у межах постачальника.
                // EN: The external offer ID is unique within its supplier.
                Offer::query()->updateOrCreate(
                    [
                        'supplier_id' => $import->supplier_id,
                        'external_id' => $offerData['external_id'],
                    ],
                    [
                        'property_id' => $property->id,
                        'offer_import_id' => $import->id,
                        'check_in' => $offerData['check_in'],
                        'check_out' => $offerData['check_out'],
                        'max_guests' => $offerData['max_guests'],
                        'price' => $offerData['price'],
                        'currency' => $offerData['currency'],
                        'available_units' => $offerData['available_units'],
                        'expires_at' => $offerData['expires_at'],
                    ],
                );

                $processedOffers++;
            }

            // UA: Фіксуємо завершення лише після обробки всього пакета.
            // EN: Mark the import as completed only after processing the whole batch.
            $import->update([
                'status' => ImportStatus::Completed,
                'processed_offers' => $processedOffers,
                'completed_at' => now(),
                'error' => null,
            ]);
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        // UA: Зберігаємо остаточну помилку поза транзакцією обробки.
        // EN: Persist the final failure outside the processing transaction.
        DB::transaction(function () use ($exception): void {
            $import = OfferImport::query()
                ->lockForUpdate()
                ->find($this->importId);

            // UA: Не змінюємо успішний результат через збій дубльованої Job.
            // EN: Do not overwrite a successful result because a duplicate Job failed.
            if (
                $import === null
                || $import->status === ImportStatus::Completed
            ) {
                return;
            }

            $import->update([
                'status' => ImportStatus::Failed,
                'error' => $exception?->getMessage()
                    ?? 'Offer import processing failed.',
                'processed_offers' => 0,
                'completed_at' => null,
            ]);
        }, 3);
    }
}
