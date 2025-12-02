<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class RegisterMissingClientsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $filePath = database_path('seeders/client_data.json');
        
        if (!file_exists($filePath)) {
            $this->command->error("File not found: {$filePath}");
            return;
        }
        
        $clientData = json_decode(file_get_contents($filePath), true);
        
        if (!$clientData) {
            $this->command->error("Failed to parse JSON data");
            return;
        }
        
        $created = 0;
        $existing = 0;
        $errors = 0;
        
        foreach ($clientData as $data) {
            $email = strtolower(trim($data['email']));
            
            if (empty($email)) {
                continue;
            }
            
            // Check if user already exists
            $existingUser = User::where('email', $email)->first();
            
            if ($existingUser) {
                $existing++;
                continue;
            }
            
            try {
                // Extract first and last name from email if no name in data
                $emailParts = explode('@', $email);
                $namePart = $emailParts[0];
                $defaultName = ucwords(str_replace(['.', '_', '-'], ' ', $namePart));
                
                // Create the user
                User::create([
                    'name' => $defaultName,
                    'email' => $email,
                    'password' => Hash::make('password123'), // Default password
                    'phonenumber' => $data['phone'] ?: null,
                    'company_name' => $data['company'] ?: null,
                    'role' => 'client',
                    'account_status' => 'active',
                    'created_by_name' => $data['created_by'] ?: 'System',
                    'bio' => 'Client imported from shoot history',
                    'avatar' => null,
                ]);
                
                $created++;
            } catch (\Exception $e) {
                $errors++;
                Log::error("Error creating client {$email}: " . $e->getMessage());
            }
        }
        
        $this->command->info("Created {$created} new client accounts.");
        $this->command->info("Found {$existing} existing client accounts.");
        if ($errors > 0) {
            $this->command->warn("{$errors} errors occurred. Check logs for details.");
        }
    }
}

