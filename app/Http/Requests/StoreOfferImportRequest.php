<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfferImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // UA: Автентифікація постачальників не визначена поточним ТЗ.
        // EN: Supplier authentication is not defined by the current assignment.
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier' => [
                'required',
                'string',
                'max:100',
                'exists:suppliers,slug',
            ],

            'external_import_id' => ['required', 'string', 'max:255'],
            'sent_at' => ['required', 'date'],

            'offers' => ['required', 'array', 'list', 'min:1'],

            // UA: Дозволяємо лише очікувані поля пропозиції.
            // EN: Allow only the expected offer fields.
            'offers.*' => [
                'required',
                'array:external_id,property,check_in,check_out,max_guests,price,currency,available_units,expires_at',
            ],

            'offers.*.external_id' => [
                'required',
                'string',
                'max:255',
                'distinct:strict',
            ],

            'offers.*.property' => ['required', 'array:code,name,city'],
            'offers.*.property.code' => ['required', 'string', 'max:100'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.city' => ['required', 'string', 'max:120'],

            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => [
                'required',
                'date_format:Y-m-d',
                'after:offers.*.check_in',
            ],

            'offers.*.max_guests' => [
                'required',
                'integer',
                'min:1',
                'max:4294967295',
            ],

            // UA: Межі відповідають DECIMAL(10, 2).
            // EN: The limits match DECIMAL(10, 2).
            'offers.*.price' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:99999999.99',
            ],

            'offers.*.currency' => [
                'required',
                'string',
                'regex:/^[A-Z]{3}$/',
            ],

            'offers.*.available_units' => [
                'required',
                'integer',
                'min:0',
                'max:4294967295',
            ],

            'offers.*.expires_at' => ['required', 'date'],
        ];
    }
}