<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class ImportFreshDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = 'c:\\Users\\shubh\\Downloads\\Shoot History (2).csv';
        
        if (!file_exists($csvPath)) {
            $this->command->error("CSV file not found: {$csvPath}");
            return;
        }
        
        $this->command->info('Reading CSV file...');
        
        $clients = [];
        $shoots = [];
        
        // Read CSV
        $handle = fopen($csvPath, 'r');
        $headers = fgetcsv($handle); // Skip header row
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 26) continue;
            
            $clientEmail = strtolower(trim($row[3])); // Client Email
            
            if (empty($clientEmail)) continue;
            
            // Store unique clients
            if (!isset($clients[$clientEmail])) {
                $clients[$clientEmail] = [
                    'name' => trim($row[2]), // Client
                    'email' => $clientEmail,
                    'phone' => trim($row[4]), // Client Phone
                    'company' => trim($row[5]), // Client Company
                    'created_by' => trim($row[25]) // User Account Created By
                ];
            }
            
            // Store shoots
            $shoots[] = [
                'client_email' => $clientEmail,
                'scheduled_date' => trim($row[0]), // Scheduled
                'completed_date' => trim($row[1]), // Completed
                'photographer' => trim($row[13]), // Photographer
                'services' => trim($row[14]), // Services
                'address' => trim($row[7]), // Address
                'city' => trim($row[9]), // City
                'state' => trim($row[10]), // State
                'zip' => trim($row[11]), // Zip
                'base_quote' => str_replace(['$', ','], '', trim($row[15])) ?: '0', // Base Quote
                'tax_rate' => str_replace('%', '', trim($row[16])) ?: '0', // Tax
                'tax_amount' => str_replace(['$', ','], '', trim($row[17])) ?: '0', // Tax Amount
                'total_quote' => str_replace(['$', ','], '', trim($row[18])) ?: '0', // Total Quote
                'total_paid' => str_replace(['$', ','], '', trim($row[19])) ?: '0', // Total Paid
                'last_payment_date' => trim($row[20]), // Last Payment Date
                'last_payment_type' => trim($row[21]), // Last Payment Type
                'tour_purchased' => trim($row[22]), // Tour Purchased
                'shoot_notes' => trim($row[23]), // Shoot Notes
                'photographer_notes' => trim($row[24]), // Photographer Notes
                'created_by' => trim($row[25]) // User Account Created By
            ];
        }
        
        fclose($handle);
        
        $this->command->info('Found ' . count($clients) . ' unique clients');
        $this->command->info('Found ' . count($shoots) . ' shoots');
        
        // Create clients
        $this->command->info('Creating client accounts...');
        $createdClients = 0;
        
        foreach ($clients as $email => $clientData) {
            // Extract first and last name from name
            $nameParts = explode(' ', $clientData['name'], 2);
            $firstName = $nameParts[0] ?: 'Client';
            $lastName = $nameParts[1] ?? '';
            
            User::create([
                'name' => $firstName . ($lastName ? ' ' . $lastName : ''),
                'email' => $email,
                'password' => Hash::make('password123'),
                'phonenumber' => $clientData['phone'] ?: null,
                'company_name' => $clientData['company'] ?: null,
                'role' => 'client',
                'account_status' => 'active',
                'created_by_name' => $clientData['created_by'] ?: 'System',
                'bio' => 'Client imported from shoot history',
                'avatar' => null,
                'username' => null,
            ]);
            
            $createdClients++;
        }
        
        $this->command->info("Created {$createdClients} client accounts");
        
        // Create shoots
        $this->command->info('Creating shoots...');
        $createdShoots = 0;
        $skippedShoots = 0;
        
        // Get default service
        $defaultService = DB::table('services')->first();
        if (!$defaultService) {
            // Create a default service
            $defaultServiceId = DB::table('services')->insertGetId([
                'name' => 'Standard Photo Package',
                'description' => 'Standard photography service',
                'category_id' => 1,
                'base_price' => 175.00,
                'delivery_time' => '3-5 business days',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $defaultServiceId = $defaultService->id;
        }
        
        foreach ($shoots as $shootData) {
            // Find client
            $client = User::where('email', $shootData['client_email'])->first();
            
            if (!$client) {
                $skippedShoots++;
                continue;
            }
            
            // Parse dates
            $scheduledDate = $this->parseDate($shootData['scheduled_date']);
            $completedDate = $this->parseDate($shootData['completed_date']);
            
            // Determine status
            $status = $completedDate ? 'completed' : 'booked';
            
            // Determine payment status
            $totalPaid = (float) $shootData['total_paid'];
            $totalQuote = (float) $shootData['total_quote'];
            $paymentStatus = 'unpaid';
            if ($totalPaid > 0) {
                $paymentStatus = ($totalPaid >= $totalQuote) ? 'paid' : 'partial';
            }
            
            // Combine notes
            $notes = [];
            if (!empty($shootData['shoot_notes'])) {
                $notes[] = "Shoot Notes: " . $shootData['shoot_notes'];
            }
            if (!empty($shootData['photographer_notes'])) {
                $notes[] = "Photographer Notes: " . $shootData['photographer_notes'];
            }
            if (!empty($shootData['tour_purchased'])) {
                $notes[] = "Tour: " . $shootData['tour_purchased'];
            }
            if (!empty($shootData['services'])) {
                $notes[] = "Services: " . $shootData['services'];
            }
            $combinedNotes = !empty($notes) ? implode("\n\n", $notes) : null;
            
            try {
                DB::table('shoots')->insert([
                    'client_id' => $client->id,
                    'photographer_id' => null,
                    'service_id' => $defaultServiceId,
                    'address' => $shootData['address'] ?: 'N/A',
                    'city' => $shootData['city'] ?: 'N/A',
                    'state' => $shootData['state'] ?: 'N/A',
                    'zip' => $shootData['zip'] ?: '00000',
                    'scheduled_date' => $scheduledDate,
                    'time' => null,
                    'base_quote' => $shootData['base_quote'],
                    'tax_amount' => $shootData['tax_amount'],
                    'total_quote' => $shootData['total_quote'],
                    'payment_status' => $paymentStatus,
                    'payment_type' => $shootData['last_payment_type'] ?: null,
                    'notes' => $combinedNotes,
                    'status' => $status,
                    'created_by' => $shootData['created_by'] ?: 'System',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                $createdShoots++;
            } catch (\Exception $e) {
                $this->command->warn("Error creating shoot: " . $e->getMessage());
                $skippedShoots++;
            }
        }
        
        $this->command->info("Created {$createdShoots} shoots");
        $this->command->warn("Skipped {$skippedShoots} shoots");
        
        $this->command->info('✅ Data import completed successfully!');
    }
    
    private function parseDate($dateStr)
    {
        if (empty($dateStr)) {
            return null;
        }
        
        try {
            $date = \DateTime::createFromFormat('n/j/Y', $dateStr);
            if ($date) {
                return $date->format('Y-m-d');
            }
        } catch (\Exception $e) {
            // Ignore
        }
        
        return null;
    }
}

