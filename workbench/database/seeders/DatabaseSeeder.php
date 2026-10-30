<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Hypervel\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AclSeeder::class);
    }
}
