<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ExchangeRate;

class ExchangeRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ExchangeRate::firstOrCreate([
            'currency' => 'USD',
            'rate_to_usd' => 1.0,
        ]);

        ExchangeRate::firstOrCreate([
            'currency' => 'EUR',
            'rate_to_usd' => 1.16,
        ]);

        ExchangeRate::firstOrCreate([
            'currency' => 'GBP',
            'rate_to_usd' => 1.35,
        ]);

        ExchangeRate::firstOrCreate([
            'currency' => 'UAH',
            'rate_to_usd' => 0.022,
        ]);
    }
}
