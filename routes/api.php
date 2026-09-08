<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OfferImportController;

Route::post('/imports', [OfferImportController::class, 'store']);
Route::get('/imports/{import}', [OfferImportController::class, 'show']);