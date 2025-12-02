<?php

namespace App\Console\Commands;

use App\Models\PhotographerAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SeedPhotographerAvailability extends Command
{
    protected $signature = 'photographers:seed-availability 
        {--start=09:00 : Default start time (24h format)} 
        {--end=17:00 : Default end time (24h format)} 
        {--days=mon,tue,wed,thu,fri : Comma separated days}';

    protected $description = 'Ensure every photographer has recurring availability windows';

    public function handle(): int
    {
        $start = $this->option('start');
        $end = $this->option('end');
        $days = array_filter(array_map('trim', explode(',', $this->option('days'))));
        $days = array_map(function ($day) {
            $normalized = strtolower($day);
            return match ($normalized) {
                'mon' => 'monday',
                'tue' => 'tuesday',
                'wed' => 'wednesday',
                'thu' => 'thursday',
                'fri' => 'friday',
                'sat' => 'saturday',
                'sun' => 'sunday',
                default => $normalized,
            };
        }, $days);

        if (!$this->isValidTime($start) || !$this->isValidTime($end) || $end <= $start) {
            $this->error('Invalid start/end time provided.');
            return Command::FAILURE;
        }

        if (empty($days)) {
            $this->error('At least one day must be provided.');
            return Command::FAILURE;
        }

        $photographers = User::where('role', 'photographer')->get();

        if ($photographers->isEmpty()) {
            $this->warn('No photographers found.');
            return Command::SUCCESS;
        }

        $created = 0;

        foreach ($photographers as $photographer) {
            foreach ($days as $day) {
                $existing = PhotographerAvailability::where('photographer_id', $photographer->id)
                    ->whereNull('date')
                    ->where('day_of_week', $day)
                    ->where('start_time', $start)
                    ->where('end_time', $end)
                    ->first();

                if ($existing) {
                    continue;
                }

                PhotographerAvailability::create([
                    'photographer_id' => $photographer->id,
                    'date' => null,
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'status' => 'available',
                ]);

                $created++;
            }
        }

        $this->info("Seeded {$created} availability windows.");

        return Command::SUCCESS;
    }

    protected function isValidTime(string $value): bool
    {
        try {
            Carbon::createFromFormat('H:i', $value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

