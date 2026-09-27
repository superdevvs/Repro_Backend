<?php

namespace Database\Seeders;

use App\Services\Messaging\SystemAutomationDefaults;
use Illuminate\Database\Seeder;

class SystemAutomationsSeeder extends Seeder
{
    public function run(): void
    {
        app(SystemAutomationDefaults::class)->ensure();
    }
}
