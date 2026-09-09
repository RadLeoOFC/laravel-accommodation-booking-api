<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            // UA: Зберігаємо зв’язок із пропозицією та забороняємо її видалення за наявності бронювань.
            // EN: Link the offer and prevent its deletion while reservations exist.
            $table->foreignId('offer_id')
                ->constrained('offers')
                ->restrictOnDelete();

            // UA: Один зовнішній ID замовлення відповідає одному бронюванню.
            // EN: One external order ID identifies one reservation.
            $table->string('client_reference')->unique();

            // UA: Контактні дані клієнта на момент бронювання.
            // EN: Customer contact details at the time of booking.
            $table->string('customer_name');
            $table->string('customer_email');

            // UA: Фіксуємо дати та ціну, щоб подальші імпорти не змінювали умови бронювання.
            // EN: Snapshot the dates and price so later imports cannot change the booking terms.
            $table->date('check_in');
            $table->date('check_out');
            $table->decimal('price', 10, 2);
            $table->string('currency', 3);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
