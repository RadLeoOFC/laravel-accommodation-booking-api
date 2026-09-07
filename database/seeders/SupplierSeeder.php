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
            'slug' => 'supplier-a',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        Supplier::firstOrCreate([
            'slug' => 'supplier-b',
            'name' => 'Supplier B',
            'is_active' => true,
        ]);
    }
}
