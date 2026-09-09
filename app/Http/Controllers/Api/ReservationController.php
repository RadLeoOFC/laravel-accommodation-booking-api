<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Actions\CreateReservation;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function store(
        StoreReservationRequest $request,
        Offer $offer,
        CreateReservation $createReservation
    ): JsonResponse {
        // UA: Передаємо ID пропозиції та перевірені дані для безпечного бронювання.
        // EN: Pass the offer ID and validated input for safe reservation creation.
        $reservation = $createReservation->handle(
            $offer->id,
            $request->validated()
        );

        // UA: Нова бронь повертає 201; повторний запит — наявну бронь зі статусом 200.
        // EN: A new reservation returns 201; a repeated request returns the existing reservation with 200.
        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode($reservation->wasRecentlyCreated ? 201 : 200);
    }
}
