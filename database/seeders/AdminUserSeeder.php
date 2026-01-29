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
            ['email' => 'aj@reprophotos.com'],
            [
                'name' => 'AJ',
                'username' => 'aj',
                'phonenumber' => null,
                'company_name' => null,
                'role' => 'superadmin',
                'avatar' => null,
                'bio' => null,
                'account_status' => 'active',
                'password' => Hash::make('PowerMove*5484'),
            ]
        );

        // Admin
        User::updateOrCreate(
            ['email' => 'creator@reprophotos.com'],
            [
                'name' => 'Creator',
                'username' => 'creator',
                'phonenumber' => null,
                'company_name' => null,
                'role' => 'admin',
                'avatar' => null,
                'bio' => null,
                'account_status' => 'active',
                'password' => Hash::make('ReCreate1!'),
            ]
        );

        User::whereIn('role', ['admin', 'superadmin'])
            ->whereNotIn('email', ['aj@reprophotos.com', 'creator@reprophotos.com'])
            ->update(['role' => 'client']);
    }
}

