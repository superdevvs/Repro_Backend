<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class ResetAdminPasswordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@reprophotos.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
                'role' => 'superadmin',
                'account_status' => 'active',
                'phonenumber' => '(202) 868-1663',
                'company_name' => 'R/E Pro Photos',
                'bio' => 'System Administrator',
                'username' => 'superadmin',
                'created_by_name' => 'System',
            ]
        );

        $this->command->info('Admin credentials reset.');
        $this->command->info('Email: admin@reprophotos.com');
        $this->command->info('Password: admin123');
    }
}

