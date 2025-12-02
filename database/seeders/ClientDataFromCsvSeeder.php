<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientDataFromCsvSeeder extends Seeder
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
        
        $updated = 0;
        $notFound = 0;
        
        foreach ($clientData as $data) {
            $email = strtolower(trim($data['email']));
            
            if (empty($email)) {
                continue;
            }
            
            $user = DB::table('users')->where('email', $email)->first();
            
            if ($user) {
                DB::table('users')
                    ->where('email', $email)
                    ->update([
                        'phonenumber' => $data['phone'] ?: $user->phonenumber,
                        'company_name' => $data['company'] ?: $user->company_name,
                        'created_by_name' => $data['created_by'],
                        'updated_at' => now(),
                    ]);
                $updated++;
            } else {
                $notFound++;
                Log::info("User not found for email: {$email}");
            }
        }
        
        $this->command->info("Updated {$updated} client records.");
        $this->command->info("{$notFound} email addresses not found in the database.");
    }
}
