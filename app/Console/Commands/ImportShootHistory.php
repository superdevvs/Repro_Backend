<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportShootHistory extends Command
{
    protected $signature = 'import:shoot-history {--path= : CSV path relative to base path} {--limit=30} {--offset=0}';

    protected $description = 'Import sample shoots from a historical CSV file';

    public function handle(): int
    {
        $path = $this->option('path')
            ? base_path($this->option('path'))
            : database_path('seeders/data/shoot_history_sample.csv');

        if (!file_exists($path)) {
            $this->error("File not found at {$path}");
            return Command::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $offset = max(0, (int) $this->option('offset'));
        $imported = 0;

        if (($handle = fopen($path, 'r')) === false) {
            $this->error('Unable to open CSV file.');
            return Command::FAILURE;
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            $this->error('CSV appears to be empty.');
            return Command::FAILURE;
        }

        while (($row = fgetcsv($handle)) !== false) {
            if ($offset > 0) {
                $offset--;
                continue;
            }

            if ($limit && $imported >= $limit) {
                break;
            }

            $data = array_combine($headers, $row);
            if (!$data) {
                continue;
            }

                $client = $this->upsertUser($data['Client'], $data['Client Email'], $data['Client Phone'], $data['Client Company'], 'client');
                $photographerName = $this->normalizeName($data['Photographer']);
                $photographer = $this->upsertUser(
                    $photographerName,
                    Str::slug($photographerName, '.') . '@photographers.repro',
                    null,
                    null,
                    'photographer'
                );
                $service = $this->upsertService($data['Services'], $data['Base Quote']);
            $rep = $this->upsertRep($data['User Account Created By'] ?? null);

                $scheduled = $this->parseDate($data['Scheduled']);
                $completed = $this->parseDate($data['Completed']);

                Shoot::create([
                    'client_id' => $client->id,
                    'photographer_id' => $photographer->id,
                    'service_id' => $service->id,
                    'service_category' => null,
                    'address' => $data['Address'],
                    'city' => $data['City'],
                    'state' => $data['State'],
                    'zip' => $data['Zip'],
                    'scheduled_date' => $scheduled,
                    'time' => '10:00',
                    'base_quote' => $this->parseMoney($data['Base Quote']),
                    'tax_amount' => $this->parseMoney($data['Tax Amount']),
                    'total_quote' => $this->parseMoney($data['Total Quote']),
                    'payment_status' => $this->parseMoney($data['Total Paid']) > 0 ? 'paid' : 'unpaid',
                    'payment_type' => $data['Last Payment Type'] ?: null,
                    'notes' => $data['Shoot Notes'] ?: null,
                    'photographer_notes' => $data['Photographer Notes'] ?: null,
                    'status' => $completed ? 'completed' : 'scheduled',
                    'workflow_status' => $completed ? Shoot::WORKFLOW_COMPLETED : Shoot::WORKFLOW_BOOKED,
                'created_by' => $rep?->name ?? ($data['User Account Created By'] ?: 'Import Script'),
                ]);

            $imported++;
        }

        fclose($handle);

        $this->info("Imported {$imported} shoots.");
        return Command::SUCCESS;
    }

    protected function upsertUser(?string $name, ?string $email, ?string $phone, ?string $company, string $role): User
    {
        $email = $email ?: Str::slug($name ?? Str::random(6), '.') . "@{$role}.repro";

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name ?: Str::title($role . ' ' . Str::random(5)),
                'username' => Str::slug(($name ?: $email) . '-' . $role),
                'phonenumber' => $phone,
                'company_name' => $company,
                'role' => $role,
                'password' => Hash::make(Str::random(16)),
                'account_status' => 'active',
            ]
        );
    }

    protected function upsertRep(?string $name): ?User
    {
        $repName = trim((string) $name);
        if ($repName === '') {
            return null;
        }

        return $this->upsertUser($repName, null, null, null, 'sales_rep');
    }

    protected function upsertService(?string $name, ?string $price): Service
    {
        $serviceName = trim($name ?: 'General Media Package');
        $servicePrice = $this->parseMoney($price) ?: 250;

        $categoryId = Category::query()->value('id');
        if (!$categoryId) {
            $category = Category::create([
                'name' => 'General',
            ]);
            $categoryId = $category->id;
        }

        return Service::firstOrCreate(
            ['name' => Str::limit($serviceName, 190)],
            [
                'description' => $serviceName,
                'price' => $servicePrice,
                'delivery_time' => '48h',
                'category_id' => $categoryId,
            ]
        );
    }

    protected function parseMoney(?string $value): float
    {
        if (!$value) {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d.\-]/', '', $value);
        return (float) ($normalized ?: 0);
    }

    protected function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        return Carbon::createFromFormat('m/d/Y', $value)->startOfDay();
    }

    protected function normalizeName(?string $value): string
    {
        if (!$value) {
            return 'Unknown Photographer';
        }

        $clean = preg_split('/R\/E|,|;|&/', $value)[0];
        return trim($clean);
    }
}

