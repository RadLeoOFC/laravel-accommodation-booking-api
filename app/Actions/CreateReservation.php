<?php

namespace App\Actions;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CreateReservation
{
    /**
     * @param array{
     *     client_reference: string,
     *     customer_name: string,
     *     customer_email: string
     * } $data
     */
    public function handle(int $offerId, array $data): Reservation
    {
        // UA: Швидко повертаємо раніше створену бронь для повторного запиту.
        // EN: Quickly return an existing reservation for a repeated request.
        $existing = Reservation::query()
            ->where('client_reference', $data['client_reference'])
            ->first();

        if ($existing !== null) {
            return $this->resolveExisting($existing, $offerId, $data);
        }

        try {
            return DB::transaction(function () use ($offerId, $data): Reservation {
                // UA: Повторно читаємо пропозицію та блокуємо її до завершення транзакції.
                // EN: Read the offer again and lock it until the transaction finishes.
                $offer = Offer::query()
                    ->lockForUpdate()
                    ->findOrFail($offerId);

                // UA: Поки ми чекали блокування, інший запит міг створити цю бронь.
                // EN: Another request may have created this reservation while we waited.
                // UA: Блокувальне читання отримує поточний стан запису.
                // EN: A locking read retrieves the record's current state.
                $existing = Reservation::query()
                    ->where('client_reference', $data['client_reference'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->resolveExisting($existing, $offerId, $data);
                }

                // UA: Без строку дії або після його завершення пропозиція недоступна.
                // EN: An offer is unavailable if its expiration is missing or has passed.
                if (
                    $offer->expires_at === null
                    || $offer->expires_at->lessThanOrEqualTo(now())
                ) {
                    throw new ConflictHttpException(
                        'The offer is no longer valid.'
                    );
                }

                // UA: Перевіряємо залишок лише після отримання блокування.
                // EN: Check stock only after acquiring the lock.
                if ($offer->available_units < 1) {
                    throw new ConflictHttpException(
                        'The offer has no available units.'
                    );
                }

                // UA: Списання та створення броні є однією атомарною операцією.
                // EN: Stock deduction and reservation creation form one atomic operation.
                $offer->decrement('available_units');

                // UA: Ціну, валюту та дати копіюємо з пропозиції, а не з запиту.
                // EN: Copy the price, currency, and dates from the offer, not the request.
                return Reservation::query()->create([
                    'offer_id' => $offer->id,
                    'client_reference' => $data['client_reference'],
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'],
                    'check_in' => $offer->check_in->toDateString(),
                    'check_out' => $offer->check_out->toDateString(),
                    'price' => $offer->price,
                    'currency' => $offer->currency,
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // UA: Паралельний запит міг зайняти client_reference після нашої перевірки.
            // EN: A concurrent request may have claimed client_reference after our check.
            // UA: На цьому етапі невдала транзакція та списання вже відкочені.
            // EN: At this point, the failed transaction and stock deduction are rolled back.
            $existing = Reservation::query()
                ->where('client_reference', $data['client_reference'])
                ->first();

            // UA: Не приховуємо порушення інших унікальних обмежень.
            // EN: Do not hide violations of other unique constraints.
            if ($existing === null) {
                throw $exception;
            }

            return $this->resolveExisting($existing, $offerId, $data);
        }
    }

    private function resolveExisting(
        Reservation $reservation,
        int $offerId,
        array $data
    ): Reservation {
        // UA: Повтор має стосуватися того самого замовлення та контактних даних.
        // EN: A retry must refer to the same order and contact details.
        if (
            (int) $reservation->offer_id !== $offerId
            || $reservation->customer_name !== $data['customer_name']
            || $reservation->customer_email !== $data['customer_email']
        ) {
            throw new ConflictHttpException(
                'The client reference is already used for a different reservation.'
            );
        }

        // UA: Повертаємо наявну бронь без повторної перевірки доступності та списання.
        // EN: Return the existing reservation without checking availability or deducting again.
        return $reservation;
    }
}