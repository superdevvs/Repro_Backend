<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class CreateAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if admin already exists
        $existingAdmin = User::where('email', 'admin@reprophotos.com')->first();
        
        if ($existingAdmin) {
            $this->command->info('Admin account already exists: admin@reprophotos.com');
            return;
        }
        
        // Create admin account
        User::create([
            'name' => 'Super Admin',
            'email' => 'admin@reprophotos.com',
            'password' => Hash::make('admin123'),
            'role' => 'superadmin',
            'account_status' => 'active',
            'phonenumber' => '(202) 868-1663',
            'company_name' => 'R/E Pro Photos',
            'bio' => 'System Administrator',
            'username' => null,
            'avatar' => null,
            'created_by_name' => 'System',
        ]);
        
        $this->command->info('✅ Admin account created successfully!');
        $this->command->info('Email: admin@reprophotos.com');
        $this->command->info('Password: admin123');
    }
}

