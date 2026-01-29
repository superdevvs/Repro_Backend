<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    /**
     * Import accounts from CSV file
     */
    public function importAccounts(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'dry_run' => 'boolean',
        ]);

        $file = $request->file('file');
        $dryRun = $request->boolean('dry_run', false);

        try {
            $handle = fopen($file->getRealPath(), 'r');
            if ($handle === false) {
                return response()->json(['error' => 'Could not open file'], 400);
            }

            // Read header row
            $headers = fgetcsv($handle);
            if ($headers === false) {
                fclose($handle);
                return response()->json(['error' => 'Could not read CSV headers'], 400);
            }

            // Normalize headers
            $headers = array_map(function ($h) {
                return strtolower(trim($h));
            }, $headers);

            $imported = 0;
            $skipped = 0;
            $updated = 0;
            $errors = [];
            $row = 1;
            $importedUsers = [];

            while (($data = fgetcsv($handle)) !== false) {
                $row++;

                // Skip empty rows
                if (count(array_filter($data)) === 0) {
                    continue;
                }

                // Map CSV data to associative array
                $record = [];
                foreach ($headers as $index => $header) {
                    $record[$header] = $data[$index] ?? '';
                }

                // Extract fields
                $name = trim($record['name'] ?? '');
                $email = trim(strtolower($record['email'] ?? ''));
                $accountType = trim($record['account type'] ?? $record['role'] ?? 'client');
                $phone = $this->formatPhone($record['phone'] ?? $record['phonenumber'] ?? '');
                $company = trim($record['company'] ?? $record['company_name'] ?? '');
                $createdBy = trim($record['created by'] ?? $record['created_by_name'] ?? '');

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
                            $importedUsers[] = [
                                'action' => 'updated',
                                'name' => $name,
                                'email' => $email,
                                'role' => $existingUser->role,
                            ];
                        } else {
                            $skipped++;
                        }
                    } else {
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
                    'password' => Hash::make(Str::random(16)),
                    'created_by_name' => $createdBy ?: auth()->user()->name ?? null,
                ];

                if (!$dryRun) {
                    try {
                        $newUser = User::create($userData);
                        $imported++;
                        $importedUsers[] = [
                            'action' => 'created',
                            'id' => $newUser->id,
                            'name' => $name,
                            'email' => $email,
                            'role' => $role,
                        ];
                    } catch (\Exception $e) {
                        $errors[] = "Row {$row}: Failed to import {$email} - " . $e->getMessage();
                        $skipped++;
                    }
                } else {
                    $imported++;
                    $importedUsers[] = [
                        'action' => 'would_create',
                        'name' => $name,
                        'email' => $email,
                        'role' => $role,
                    ];
                }
            }

            fclose($handle);

            return response()->json([
                'success' => true,
                'dry_run' => $dryRun,
                'summary' => [
                    'imported' => $imported,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'errors' => count($errors),
                ],
                'users' => $importedUsers,
                'errors' => array_slice($errors, 0, 20),
            ]);
        } catch (\Exception $e) {
            Log::error('Account import failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import shoots from CSV file
     */
    public function importShoots(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'dry_run' => 'boolean',
        ]);

        $file = $request->file('file');
        $dryRun = $request->boolean('dry_run', false);

        try {
            $handle = fopen($file->getRealPath(), 'r');
            if ($handle === false) {
                return response()->json(['error' => 'Could not open file'], 400);
            }

            // Read header row
            $headers = fgetcsv($handle);
            if ($headers === false) {
                fclose($handle);
                return response()->json(['error' => 'Could not read CSV headers'], 400);
            }

            // Normalize headers
            $headers = array_map(function ($h) {
                return strtolower(trim($h));
            }, $headers);

            $imported = 0;
            $skipped = 0;
            $errors = [];
            $row = 1;
            $importedShoots = [];

            while (($data = fgetcsv($handle)) !== false) {
                $row++;

                // Skip empty rows
                if (count(array_filter($data)) === 0) {
                    continue;
                }

                // Map CSV data to associative array
                $record = [];
                foreach ($headers as $index => $header) {
                    $record[$header] = $data[$index] ?? '';
                }

                try {
                    // Extract client info
                    $clientName = trim($record['client'] ?? '');
                    $clientEmail = trim(strtolower($record['client email'] ?? ''));
                    $clientPhone = $this->formatPhone($record['client phone'] ?? '');
                    $clientCompany = trim($record['client company'] ?? '');

                    if (empty($clientName) && empty($clientEmail)) {
                        $errors[] = "Row {$row}: Missing client name or email";
                        $skipped++;
                        continue;
                    }

                    // Find or create client
                    $client = $this->upsertUser(
                        $clientName,
                        $clientEmail,
                        $clientPhone,
                        $clientCompany,
                        'client',
                        $dryRun
                    );

                    // Extract photographer info
                    $photographerName = $this->normalizeName($record['photographer'] ?? '');
                    $photographer = null;
                    if (!empty($photographerName) && $photographerName !== 'Unknown Photographer') {
                        $photographer = $this->upsertUser(
                            $photographerName,
                            Str::slug($photographerName, '.') . '@photographers.repro',
                            null,
                            null,
                            'photographer',
                            $dryRun
                        );
                    }

                    // Extract service info
                    $serviceName = trim($record['services'] ?? $record['service'] ?? 'General Media Package');
                    $baseQuote = $this->parseMoney($record['base quote'] ?? '0');
                    $service = $this->upsertService($serviceName, $baseQuote, $dryRun);

                    // Extract rep info
                    $repName = trim($record['user account created by'] ?? $record['created by'] ?? '');
                    $rep = null;
                    if (!empty($repName)) {
                        $rep = $this->upsertUser($repName, null, null, null, 'salesRep', $dryRun);
                    }

                    // Parse dates
                    $scheduled = $this->parseDate($record['scheduled'] ?? '');
                    $completed = $this->parseDate($record['completed'] ?? '');

                    // Extract location
                    $address = trim($record['address'] ?? '');
                    $city = trim($record['city'] ?? '');
                    $state = trim($record['state'] ?? '');
                    $zip = trim($record['zip'] ?? '');

                    // Extract financial info
                    $taxAmount = $this->parseMoney($record['tax amount'] ?? '0');
                    $totalQuote = $this->parseMoney($record['total quote'] ?? '0');
                    $totalPaid = $this->parseMoney($record['total paid'] ?? '0');
                    $paymentType = trim($record['last payment type'] ?? '');

                    // Extract notes
                    $shootNotes = trim($record['shoot notes'] ?? '');
                    $photographerNotes = trim($record['photographer notes'] ?? '');

                    if (!$dryRun) {
                        $shoot = Shoot::create([
                            'client_id' => $client?->id,
                            'photographer_id' => $photographer?->id,
                            'service_id' => $service?->id,
                            'service_category' => null,
                            'address' => $address,
                            'city' => $city,
                            'state' => $state,
                            'zip' => $zip,
                            'scheduled_date' => $scheduled,
                            'time' => '10:00',
                            'base_quote' => $baseQuote,
                            'tax_amount' => $taxAmount,
                            'total_quote' => $totalQuote,
                            'payment_status' => $totalPaid > 0 ? 'paid' : 'unpaid',
                            'payment_type' => $paymentType ?: null,
                            'notes' => $shootNotes ?: null,
                            'photographer_notes' => $photographerNotes ?: null,
                            'status' => $completed ? 'completed' : 'scheduled',
                            'workflow_status' => $completed ? Shoot::WORKFLOW_COMPLETED : Shoot::WORKFLOW_BOOKED,
                            'created_by' => $rep?->name ?? ($repName ?: auth()->user()->name ?? 'Import Script'),
                        ]);

                        $imported++;
                        $importedShoots[] = [
                            'id' => $shoot->id,
                            'address' => $address,
                            'client' => $clientName,
                            'status' => $shoot->status,
                        ];
                    } else {
                        $imported++;
                        $importedShoots[] = [
                            'action' => 'would_create',
                            'address' => $address,
                            'client' => $clientName,
                            'status' => $completed ? 'completed' : 'scheduled',
                        ];
                    }
                } catch (\Exception $e) {
                    $errors[] = "Row {$row}: " . $e->getMessage();
                    $skipped++;
                }
            }

            fclose($handle);

            return response()->json([
                'success' => true,
                'dry_run' => $dryRun,
                'summary' => [
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors' => count($errors),
                ],
                'shoots' => $importedShoots,
                'errors' => array_slice($errors, 0, 20),
            ]);
        } catch (\Exception $e) {
            Log::error('Shoot import failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
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
            'salesrep' => ['role' => 'salesRep', 'status' => 'active'],
            'sales rep' => ['role' => 'salesRep', 'status' => 'active'],
            'sales_rep' => ['role' => 'salesRep', 'status' => 'active'],
            'blocked' => ['role' => 'client', 'status' => 'blocked'],
            'admin (super)' => ['role' => 'superadmin', 'status' => 'active'],
            'superadmin' => ['role' => 'superadmin', 'status' => 'active'],
            'admin/photographer' => ['role' => 'admin', 'status' => 'active'],
            'editing_manager' => ['role' => 'editing_manager', 'status' => 'active'],
        ];

        return $mapping[$accountType] ?? ['role' => 'client', 'status' => 'active'];
    }

    /**
     * Generate a unique username from email or name
     */
    protected function generateUsername(string $email, string $name): string
    {
        $baseUsername = Str::before($email, '@');
        $baseUsername = Str::slug($baseUsername, '_');

        if (strlen($baseUsername) < 3) {
            $baseUsername = Str::slug($name, '_');
        }

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
    protected function formatPhone(?string $phone): string
    {
        if (!$phone) {
            return '';
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (strlen($phone) === 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($phone, 0, 3),
                substr($phone, 3, 3),
                substr($phone, 6, 4)
            );
        }

        return $phone;
    }

    /**
     * Upsert a user
     */
    protected function upsertUser(?string $name, ?string $email, ?string $phone, ?string $company, string $role, bool $dryRun = false): ?User
    {
        if (empty($name) && empty($email)) {
            return null;
        }

        $email = $email ?: Str::slug($name ?? Str::random(6), '.') . "@{$role}.repro";

        $existing = User::where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        if ($dryRun) {
            // Return a fake user object for dry run
            $user = new User();
            $user->id = 0;
            $user->name = $name ?: Str::title($role . ' ' . Str::random(5));
            $user->email = $email;
            $user->role = $role;
            return $user;
        }

        return User::create([
            'name' => $name ?: Str::title($role . ' ' . Str::random(5)),
            'username' => Str::slug(($name ?: $email) . '-' . $role),
            'email' => $email,
            'phonenumber' => $phone,
            'company_name' => $company,
            'role' => $role,
            'password' => Hash::make(Str::random(16)),
            'account_status' => 'active',
        ]);
    }

    /**
     * Upsert a service
     */
    protected function upsertService(?string $name, float $price, bool $dryRun = false): ?Service
    {
        $serviceName = trim($name ?: 'General Media Package');
        $servicePrice = $price ?: 250;

        $existing = Service::where('name', Str::limit($serviceName, 190))->first();
        if ($existing) {
            return $existing;
        }

        if ($dryRun) {
            $service = new Service();
            $service->id = 0;
            $service->name = $serviceName;
            return $service;
        }

        $categoryId = Category::query()->value('id');
        if (!$categoryId) {
            $category = Category::create(['name' => 'General']);
            $categoryId = $category->id;
        }

        return Service::create([
            'name' => Str::limit($serviceName, 190),
            'description' => $serviceName,
            'price' => $servicePrice,
            'delivery_time' => '48h',
            'category_id' => $categoryId,
        ]);
    }

    /**
     * Parse money string to float
     */
    protected function parseMoney(?string $value): float
    {
        if (!$value) {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d.\-]/', '', $value);
        return (float) ($normalized ?: 0);
    }

    /**
     * Parse date string
     */
    protected function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            // Try common formats
            $formats = ['m/d/Y', 'Y-m-d', 'd/m/Y', 'M d, Y', 'F d, Y'];
            foreach ($formats as $format) {
                try {
                    return Carbon::createFromFormat($format, $value)->startOfDay();
                } catch (\Exception $e) {
                    continue;
                }
            }
            // Fallback to Carbon's flexible parsing
            return Carbon::parse($value)->startOfDay();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Normalize photographer name
     */
    protected function normalizeName(?string $value): string
    {
        if (!$value) {
            return 'Unknown Photographer';
        }

        $clean = preg_split('/R\/E|,|;|&/', $value)[0];
        return trim($clean);
    }

    /**
     * Get expected CSV columns for accounts
     */
    public function getAccountsTemplate()
    {
        $headers = ['Name', 'Email', 'Account Type', 'Phone', 'Company', 'Created By'];
        $sampleRow = ['John Doe', 'john@example.com', 'Client', '(555) 123-4567', 'ABC Realty', 'Admin User'];

        $csv = implode(',', $headers) . "\n" . implode(',', $sampleRow);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="accounts_template.csv"',
        ]);
    }

    /**
     * Get expected CSV columns for shoots
     */
    public function getShootsTemplate()
    {
        $headers = [
            'Client',
            'Client Email',
            'Client Phone',
            'Client Company',
            'Address',
            'City',
            'State',
            'Zip',
            'Scheduled',
            'Completed',
            'Photographer',
            'Services',
            'Base Quote',
            'Tax Amount',
            'Total Quote',
            'Total Paid',
            'Last Payment Type',
            'Shoot Notes',
            'Photographer Notes',
            'User Account Created By'
        ];
        $sampleRow = [
            'Jane Smith',
            'jane@example.com',
            '(555) 987-6543',
            'Smith Realty',
            '123 Main St',
            'Los Angeles',
            'CA',
            '90001',
            '01/15/2024',
            '',
            'John Photographer',
            'Photo Package',
            '250.00',
            '25.00',
            '275.00',
            '0.00',
            '',
            'Front door is blue',
            '',
            'Admin User'
        ];

        $csv = implode(',', $headers) . "\n" . implode(',', $sampleRow);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="shoots_template.csv"',
        ]);
    }
}
