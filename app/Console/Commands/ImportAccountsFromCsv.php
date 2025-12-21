<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportAccountsFromCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounts:import {file : Path to the CSV file} {--dry-run : Run without actually importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import accounts from a CSV file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file');
        $dryRun = $this->option('dry-run');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info($dryRun ? '🔍 DRY RUN - No changes will be made' : '📥 Starting import...');

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $this->error("Could not open file: {$filePath}");
            return 1;
        }

        // Read header row
        $headers = fgetcsv($handle);
        if ($headers === false) {
            $this->error("Could not read CSV headers");
            fclose($handle);
            return 1;
        }

        // Normalize headers
        $headers = array_map(function($h) {
            return strtolower(trim($h));
        }, $headers);

        $this->info("Found columns: " . implode(', ', $headers));

        $imported = 0;
        $skipped = 0;
        $updated = 0;
        $errors = [];
        $row = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            
            // Map CSV data to associative array
            $record = [];
            foreach ($headers as $index => $header) {
                $record[$header] = $data[$index] ?? '';
            }

            // Extract fields
            $name = trim($record['name'] ?? '');
            $email = trim(strtolower($record['email'] ?? ''));
            $accountType = trim($record['account type'] ?? 'Client');
            $phone = $this->formatPhone($record['phone'] ?? '');
            $company = trim($record['company'] ?? '');
            $createdBy = trim($record['created by'] ?? '');

            // Validate required fields
            if (empty($name) || empty($email)) {
                $errors[] = "Row {$row}: Missing name or email";
                $skipped++;
                continue;
            }

            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$row}: Invalid email format - {$email}";
                $skipped++;
                continue;
            }

            // Map account type to role and status
            $roleMapping = $this->mapAccountType($accountType);
            $role = $roleMapping['role'];
            $status = $roleMapping['status'];

            // Generate username from email
            $username = $this->generateUsername($email, $name);

            // Check if user already exists
            $existingUser = User::where('email', $email)->first();

            if ($existingUser) {
                if (!$dryRun) {
                    // Update existing user with any missing data
                    $updateData = [];
                    
                    if (empty($existingUser->phonenumber) && !empty($phone)) {
                        $updateData['phonenumber'] = $phone;
                    }
                    if (empty($existingUser->company_name) && !empty($company)) {
                        $updateData['company_name'] = $company;
                    }
                    if (empty($existingUser->created_by_name) && !empty($createdBy)) {
                        $updateData['created_by_name'] = $createdBy;
                    }
                    
                    if (!empty($updateData)) {
                        $existingUser->update($updateData);
                        $updated++;
                        $this->line("  ↻ Updated: {$name} <{$email}>");
                    } else {
                        $skipped++;
                        $this->line("  ⊘ Skipped (exists, no updates): {$name} <{$email}>");
                    }
                } else {
                    $this->line("  [DRY] Would update/skip: {$name} <{$email}>");
                    $skipped++;
                }
                continue;
            }

            // Create new user
            $userData = [
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'phonenumber' => $phone ?: null,
                'company_name' => $company ?: null,
                'role' => $role,
                'account_status' => $status,
                'password' => Hash::make(Str::random(16)), // Random password, user will reset
                'created_by_name' => $createdBy ?: null,
            ];

            if (!$dryRun) {
                try {
                    User::create($userData);
                    $imported++;
                    $this->line("  ✓ Imported: {$name} <{$email}> as {$role}");
                } catch (\Exception $e) {
                    $errors[] = "Row {$row}: Failed to import {$email} - " . $e->getMessage();
                    $skipped++;
                }
            } else {
                $this->line("  [DRY] Would import: {$name} <{$email}> as {$role}");
                $imported++;
            }
        }

        fclose($handle);

        // Summary
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info($dryRun ? '📊 DRY RUN SUMMARY' : '📊 IMPORT SUMMARY');
        $this->info('═══════════════════════════════════════');
        $this->info("  ✓ Imported: {$imported}");
        $this->info("  ↻ Updated:  {$updated}");
        $this->info("  ⊘ Skipped:  {$skipped}");
        
        if (!empty($errors)) {
            $this->newLine();
            $this->warn('⚠️  Errors encountered:');
            foreach (array_slice($errors, 0, 10) as $error) {
                $this->error("  • {$error}");
            }
            if (count($errors) > 10) {
                $this->error("  ... and " . (count($errors) - 10) . " more errors");
            }
        }

        return 0;
    }

    /**
     * Map CSV account type to system role and status
     */
    protected function mapAccountType(string $accountType): array
    {
        $accountType = strtolower(trim($accountType));

        $mapping = [
            'client' => ['role' => 'client', 'status' => 'active'],
            'photographer' => ['role' => 'photographer', 'status' => 'active'],
            'admin' => ['role' => 'admin', 'status' => 'active'],
            'editor' => ['role' => 'editor', 'status' => 'active'],
            'blocked' => ['role' => 'client', 'status' => 'blocked'],
            'admin (super)' => ['role' => 'superadmin', 'status' => 'active'],
            'admin/photographer' => ['role' => 'admin', 'status' => 'active'],
        ];

        return $mapping[$accountType] ?? ['role' => 'client', 'status' => 'active'];
    }

    /**
     * Generate a unique username from email or name
     */
    protected function generateUsername(string $email, string $name): string
    {
        // Try email prefix first
        $baseUsername = Str::before($email, '@');
        $baseUsername = Str::slug($baseUsername, '_');
        
        // If too short, use name
        if (strlen($baseUsername) < 3) {
            $baseUsername = Str::slug($name, '_');
        }

        // Ensure uniqueness
        $username = $baseUsername;
        $counter = 1;
        
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Format phone number consistently
     */
    protected function formatPhone(string $phone): string
    {
        // Remove all non-numeric characters except + for country code
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // If it's a 10-digit US number, format it
        if (strlen($phone) === 10) {
            return sprintf('(%s) %s-%s', 
                substr($phone, 0, 3),
                substr($phone, 3, 3),
                substr($phone, 6, 4)
            );
        }
        
        return $phone;
    }
}
