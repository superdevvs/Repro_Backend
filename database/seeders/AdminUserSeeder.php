<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Super Admin
        User::updateOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'phonenumber' => null,
                'company_name' => null,
                'role' => 'superadmin',
                'avatar' => null,
                'bio' => null,
                'account_status' => 'active',
                'password' => Hash::make('Password123!'),
            ]
        );

        // Admin
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'username' => 'admin',
                'phonenumber' => null,
                'company_name' => null,
                'role' => 'admin',
                'avatar' => null,
                'bio' => null,
                'account_status' => 'active',
                'password' => Hash::make('Password123!'),
            ]
        );
    }
}

