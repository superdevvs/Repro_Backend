<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        //     'username' => 'testuser',
        //     'phone_number' => '1234567890',
        //     'company' => 'Test Company',
        //     'role' => 'admin', // Or 'super admin', etc.
        //     'bio' => 'Test bio goes here',
        //     'avatar' => null, // or a default image path if needed
        //     'password' => Hash::make('password123'),
        // ]);

        $this->call([
            \Database\Seeders\AdminUserSeeder::class,
            \Database\Seeders\SalesRepSeeder::class,
            \Database\Seeders\CategorySeeder::class,
            \Database\Seeders\ServiceSeeder::class,
            \Database\Seeders\SystemAutomationsSeeder::class,
            \Database\Seeders\SampleDataSeeder::class,
        ]);
    }
}
