<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class SalesRepSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create sample sales representatives
        $salesReps = [
            [
                'name' => 'John Smith',
                'email' => 'john.smith@repro.com',
                'username' => 'johnsmith',
                'phonenumber' => '+1 (555) 123-4567',
                'company_name' => 'RE Pro Photos - East Region',
                'role' => 'salesRep',
                'password' => Hash::make('password'),
                'account_status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Sarah Johnson',
                'email' => 'sarah.johnson@repro.com',
                'username' => 'sarahjohnson',
                'phonenumber' => '+1 (555) 234-5678',
                'company_name' => 'RE Pro Photos - West Region',
                'role' => 'salesRep',
                'password' => Hash::make('password'),
                'account_status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Michael Davis',
                'email' => 'michael.davis@repro.com',
                'username' => 'michaeldavis',
                'phonenumber' => '+1 (555) 345-6789',
                'company_name' => 'RE Pro Photos - Central Region',
                'role' => 'salesRep',
                'password' => Hash::make('password'),
                'account_status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Insert sales reps
        foreach ($salesReps as $rep) {
            // Check if user already exists
            $existingUser = User::where('email', $rep['email'])->first();
            if (!$existingUser) {
                User::create($rep);
                $this->command->info("Created sales rep: {$rep['name']}");
            } else {
                $this->command->info("Sales rep already exists: {$rep['name']}");
            }
        }

        $this->command->info('SalesRepSeeder completed successfully!');
    }
}
