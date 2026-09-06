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
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->foreignId('property_id')->constrained('properties')->onDelete('cascade');
            $table->foreignId('offer_import_id')->constrained('offer_imports')->onDelete('cascade');
            $table->string('external_id');
            $table->unique(['supplier_id', 'external_id']);
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedInteger('max_guests');
            $table->decimal('price', 10, 2);
            $table->string('currency', 3);
            $table->unsignedInteger('available_units');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('property_id');
            $table->index(['check_in', 'check_out', 'property_id', 'price', 'id']);
            $table->index(['property_id', 'expires_at']);
            $table->index('supplier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
