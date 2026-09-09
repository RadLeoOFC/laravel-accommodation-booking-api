<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OfferImportController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\ReservationController;

Route::post('/imports', [OfferImportController::class, 'store']);
Route::get('/imports/{import}', [OfferImportController::class, 'show']);
Route::get('/properties', [PropertyController::class, 'index']);
Route::post('/offers/{offer}/reservations', [ReservationController::class, 'store']);