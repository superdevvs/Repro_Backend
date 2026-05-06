<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SeedPhotographerPreviousShoot extends Command
{
    protected $signature = 'photographers:seed-previous-shoot
        {--date=2026-05-08 : Shoot date for the test previous shoot}
        {--time=09:00 : Start time in 24-hour format}
        {--photographer=Ethan Cole : Photographer name or ID}
        {--dry-run : Preview without writing to the database}';

    protected $description = 'Seed one scheduled prior shoot for testing distance from previous shoot location in Book Shoot';

    public function handle(): int
    {
        $photographer = $this->resolvePhotographer((string) $this->option('photographer'));
        if (!$photographer) {
            $this->error('Photographer not found.');
            return Command::FAILURE;
        }

        $client = User::where('role', 'client')->orderBy('id')->first();
        if (!$client) {
            $this->error('No client found.');
            return Command::FAILURE;
        }

        $service = Service::orderBy('id')->first();
        if (!$service) {
            $this->error('No service found.');
            return Command::FAILURE;
        }

        $scheduledAt = Carbon::parse($this->option('date') . ' ' . $this->option('time'), config('app.timezone'));
        $marker = '[distance-test-previous-shoot]';
        $payload = [
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'address' => '2100 Clarendon Boulevard',
            'city' => 'Arlington',
            'state' => 'VA',
            'zip' => '22201',
            'scheduled_date' => $scheduledAt->toDateString(),
            'scheduled_at' => $scheduledAt,
            'time' => $scheduledAt->format('H:i'),
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'base_quote' => 250,
            'tax_amount' => 0,
            'total_quote' => 250,
            'payment_status' => 'unpaid',
            'payment_type' => null,
            'notes' => $marker . ' Prior scheduled shoot used to test distance from previous shoot location.',
            'shoot_notes' => 'Prior scheduled shoot for Book Shoot distance testing.',
            'created_by' => 'System Test Seeder',
            'updated_by' => 'System Test Seeder',
        ];

        $rows = [[
            $photographer->id,
            $photographer->name,
            $scheduledAt->format('Y-m-d H:i'),
            $payload['address'],
            $payload['city'],
            $payload['state'],
            $payload['zip'],
        ]];

        $this->table(['Photographer ID', 'Photographer', 'Scheduled At', 'Address', 'City', 'State', 'Zip'], $rows);

        if ($this->option('dry-run')) {
            $this->info('Previewed previous shoot seed.');
            return Command::SUCCESS;
        }

        $shoot = Shoot::where('photographer_id', $photographer->id)
            ->where('notes', 'like', $marker . '%')
            ->first();

        if ($shoot) {
            $shoot->fill($payload)->save();
            $this->info("Updated test previous shoot ID {$shoot->id}.");
        } else {
            $shoot = Shoot::create($payload);
            $this->info("Created test previous shoot ID {$shoot->id}.");
        }

        $this->info('Book a later shoot for the same date, for example 11:30 AM, to test distance from this previous shoot location.');

        return Command::SUCCESS;
    }

    private function resolvePhotographer(string $value): ?User
    {
        if (ctype_digit($value)) {
            return User::where('role', 'photographer')->where('id', (int) $value)->first();
        }

        return User::where('role', 'photographer')
            ->where('name', 'like', '%' . $value . '%')
            ->orderBy('id')
            ->first();
    }
}
