<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Enums\ImportStatus;
use App\Http\Requests\StoreOfferImportRequest;
use App\Jobs\ProcessOfferImport;
use App\Models\OfferImport;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use App\Http\Resources\OfferImportResource;

class OfferImportController extends Controller
{

    public function index(Request $request)
    {
        $imports = OfferImport::query()
            ->with('supplier')
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json($imports);
    }
    

    public function store(StoreOfferImportRequest $request): JsonResponse
    {
        // UA: Використовуємо лише дані, перевірені у Form Request.
        // EN: Use only the data validated by the Form Request.
        $data = $request->validated();

        // UA: Знаходимо постачальника за його зовнішнім кодом.
        // EN: Find the supplier by its external code.
        $supplier = Supplier::query()
            ->where('slug', $data['supplier'])
            ->first();

        // UA: Захист на випадок видалення постачальника після валідації.
        // EN: Handle a supplier removed after request validation.
        if ($supplier === null) {
            throw ValidationException::withMessages([
                'supplier' => 'The selected supplier does not exist.',
            ]);
        }

        // UA: Повторний запит повертає наявний імпорт без зміни його даних.
        // EN: A repeated request returns the existing import without changing it.
        $import = OfferImport::query()->firstOrCreate(
            [
                'supplier_id' => $supplier->id,
                'external_import_id' => $data['external_import_id'],
            ],
            [
                'sent_at' => $data['sent_at'],
                'status' => ImportStatus::Pending,
                'payload' => $data['offers'],
                'total_offers' => count($data['offers']),
                'processed_offers' => 0,
            ],
        );

        // UA: Ставимо Job у чергу лише для щойно створеного імпорту.
        // EN: Dispatch the Job only for a newly created import.
        if ($import->wasRecentlyCreated) {
            // UA: Передаємо лише ID; пропозиції Job прочитає з payload у БД.
            // EN: Pass only the ID; the Job will read offers from the database payload.
            ProcessOfferImport::dispatch($import->id)
                ->onQueue('imports')
                ->afterCommit();
        }

        // UA: Формуємо відповідь через Resource без очікування обробки імпорту.
        // EN: Build the response through the Resource without waiting for import processing.
        return (new OfferImportResource($import))
            ->response()
            ->setStatusCode(202);
    }

    public function show(OfferImport $import): JsonResponse
    {
        // UA: Laravel знаходить імпорт за ID маршруту або автоматично повертає 404.
        // EN: Laravel finds the import by its route ID or automatically returns 404.

        // UA: Повертаємо поточний стан імпорту та метадані обробки.
        // EN: Return the current import status and processing metadata.
        return (new OfferImportResource($import))->response();
    }
}
