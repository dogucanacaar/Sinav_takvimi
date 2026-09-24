<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Gerçek veri olmadan sunum yapılabilmesi için demo fakülte verisi.
        $this->call(DemoSeeder::class);
    }
}
