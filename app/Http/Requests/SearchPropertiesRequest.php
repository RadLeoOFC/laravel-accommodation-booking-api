<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchPropertiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // UA: Пошук доступний без авторизації в межах цього завдання.
        // EN: Search is available without authentication for this assignment.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // UA: Обидві дати обов’язкові та мають формат YYYY-MM-DD.
            // EN: Both dates are required and must use the YYYY-MM-DD format.
            'check_in' => [
                'required',
                'date_format:Y-m-d',
            ],

            // UA: Дата виїзду повинна бути пізнішою за дату заїзду.
            // EN: The check-out date must be later than the check-in date.
            'check_out' => [
                'required',
                'date_format:Y-m-d',
                'after:check_in',
            ],

            // UA: Кількість гостей — додатне ціле число в межах unsigned integer.
            // EN: The guest count must be a positive integer within the unsigned integer range.
            'guests' => [
                'required',
                'integer',
                'min:1',
                'max:4294967295',
            ],

            // UA: Місто необов’язкове; невідоме місто просто дає порожній результат.
            // EN: City is optional; an unknown city simply returns no results.
            'city' => [
                'nullable',
                'string',
                'max:120',
            ],

            // UA: Номер сторінки починається з одиниці.
            // EN: Page numbering starts at one.
            'page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:2147483647',
            ],

            // UA: Обмежуємо розмір сторінки до 100 об’єктів.
            // EN: Limit the page size to 100 properties.
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }
}