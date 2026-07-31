<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * What a fresh install needs to run: the permission catalogue and the
     * currencies the POS assumes exist (USD default + Riel). No mock products —
     * a real deployment adds its own catalog. (The old Temp::mock() product
     * loop was removed; the Temp model no longer exists.)
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);
        $this->call(CurrencySeeder::class);
    }
}
