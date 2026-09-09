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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();

            // UA: Код валюти; для кожної валюти зберігаємо один поточний курс.
            // EN: Currency code; store one current rate per currency.
            $table->string('currency', 3)->unique();

            // UA: Вартість однієї одиниці цієї валюти в USD.
            // EN: The value of one unit of this currency in USD.
            $table->decimal('rate_to_usd', 20, 10);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
