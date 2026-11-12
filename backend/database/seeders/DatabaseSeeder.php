<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data (platform registry) is seeded in every environment.
        $this->call(SocialPlatformSeeder::class);
        // Demo data is for LOCAL DEVELOPMENT ONLY.
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
