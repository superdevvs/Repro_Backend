<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Service;

class ShootsFromCsvSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $filePath = database_path('seeders/shoots_data.json');
        
        if (!file_exists($filePath)) {
            $this->command->error("File not found: {$filePath}");
            return;
        }
        
        $shootsData = json_decode(file_get_contents($filePath), true);
        
        if (!$shootsData) {
            $this->command->error("Failed to parse JSON data");
            return;
        }
        
        $inserted = 0;
        $skipped = 0;
        $errors = 0;
        
        // Get first available service or skip if none exist
        $defaultService = Service::first();
        
        if (!$defaultService) {
            $this->command->error("No services found in database. Please create services first.");
            return;
        }
        
        foreach ($shootsData as $data) {
            try {
                // Find client by email
                $client = User::where('email', strtolower(trim($data['client_email'])))->first();
                
                if (!$client) {
                    $skipped++;
                    Log::info("Client not found for email: {$data['client_email']}");
                    continue;
                }
                
                // Find photographer by name (approximate match)
                $photographer = null;
                if (!empty($data['photographer_name'])) {
                    $photographer = User::where('role', 'photographer')
                        ->where('name', 'like', '%' . trim($data['photographer_name']) . '%')
                        ->first();
                }
                
                // Determine payment status
                $totalPaid = floatval($data['total_paid']);
                $totalQuote = floatval($data['total_quote']);
                $paymentStatus = 'unpaid';
                if ($totalPaid >= $totalQuote && $totalQuote > 0) {
                    $paymentStatus = 'paid';
                } elseif ($totalPaid > 0) {
                    $paymentStatus = 'partial';
                }
                
                // Determine status
                $status = !empty($data['completed_date']) ? 'completed' : 'booked';
                
                // Combine notes into single notes field
                $notes = [];
                if (!empty($data['shoot_notes'])) {
                    $notes[] = "Shoot Notes: " . $data['shoot_notes'];
                }
                if (!empty($data['photographer_notes'])) {
                    $notes[] = "Photographer Notes: " . $data['photographer_notes'];
                }
                if (!empty($data['tour_purchased'])) {
                    $notes[] = "Tour Purchased: " . $data['tour_purchased'];
                }
                $combinedNotes = !empty($notes) ? implode("\n\n", $notes) : null;
                
                // Insert shoot
                DB::table('shoots')->insert([
                    'client_id' => $client->id,
                    'photographer_id' => $photographer ? $photographer->id : null,
                    'service_id' => $defaultService->id,
                    'address' => $data['address'] ?: 'N/A',
                    'city' => $data['city'] ?: 'N/A',
                    'state' => $data['state'] ?: 'N/A',
                    'zip' => $data['zip'] ?: '00000',
                    'scheduled_date' => $this->parseDate($data['scheduled_date']),
                    'time' => null,
                    'base_quote' => $data['base_quote'],
                    'tax_amount' => $data['tax_amount'],
                    'total_quote' => $data['total_quote'],
                    'payment_status' => $paymentStatus,
                    'payment_type' => $data['last_payment_type'] ?: null,
                    'notes' => $combinedNotes,
                    'status' => $status,
                    'created_by' => $data['created_by'] ?: 'System',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                $inserted++;
            } catch (\Exception $e) {
                $errors++;
                Log::error("Error inserting shoot: " . $e->getMessage());
            }
        }
        
        $this->command->info("Inserted {$inserted} shoot records.");
        $this->command->info("Skipped {$skipped} shoots (client not found).");
        if ($errors > 0) {
            $this->command->warn("{$errors} errors occurred. Check logs for details.");
        }
    }
    
    private function parseDate($dateString)
    {
        if (empty($dateString)) {
            return null;
        }
        
        try {
            // Try M/D/YYYY format
            $parts = explode('/', $dateString);
            if (count($parts) === 3) {
                [$month, $day, $year] = $parts;
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        } catch (\Exception $e) {
            Log::warning("Could not parse date: {$dateString}");
        }
        
        return null;
    }
}
