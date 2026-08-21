<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Production administrators are intentionally never provisioned from
        // environment variables: a leftover bootstrap value could silently
        // reset a strong password on a later `db:seed`. Use the hidden,
        // interactive `php artisan admin:create` prompt instead.
    }
}
