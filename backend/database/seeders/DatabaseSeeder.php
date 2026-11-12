<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Demo data is for LOCAL DEVELOPMENT ONLY.
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
