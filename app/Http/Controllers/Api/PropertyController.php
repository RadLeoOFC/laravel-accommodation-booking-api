<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertySearchResource;
use App\Queries\PropertySearchQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function index(
        SearchPropertiesRequest $request,
        PropertySearchQuery $search
    ): AnonymousResourceCollection {
        // UA: Виконуємо пошук із фільтрацією, вибором найкращої пропозиції та пагінацією в SQL.
        // EN: Search with filtering, best-offer selection, and pagination in SQL.
        $properties = $search->paginate($request->validated());

        // UA: Зберігаємо параметри пошуку в посиланнях на сусідні сторінки.
        // EN: Preserve search parameters in pagination links.
        $properties->appends($request->validated());

        // UA: Формуємо відповідь із житлом та обраними пропозиціями.
        // EN: Build the response containing properties and selected offers.
        return PropertySearchResource::collection($properties);
    }
}
