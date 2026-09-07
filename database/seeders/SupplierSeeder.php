<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Supplier;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Supplier::firstOrCreate([
            'slug' => 'supplier-1',
            'name' => 'Supplier 1',
            'is_active' => true,
        ]);

        Supplier::firstOrCreate([
            'slug' => 'supplier-2',
            'name' => 'Supplier 2',
            'is_active' => true,
        ]);
    }
}
