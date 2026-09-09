<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OfferImportController;
use App\Http\Controllers\Api\PropertyController;

Route::post('/imports', [OfferImportController::class, 'store']);
Route::get('/imports/{import}', [OfferImportController::class, 'show']);
Route::get('/properties', [PropertyController::class, 'index']);