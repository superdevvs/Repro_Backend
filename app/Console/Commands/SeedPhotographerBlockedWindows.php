<?php

namespace App\Console\Commands;

use App\Models\PhotographerAvailability;
use App\Models\User;
use Illuminate\Console\Command;

class SeedPhotographerBlockedWindows extends Command
{
    protected $signature = 'photographers:seed-blocked-windows {--dry-run : Preview without writing to the database}';

    protected $description = 'Seed varied unavailable blocks for photographer availability testing';

    private array $blockedWindows = [
        ['day' => 'monday', 'start' => '10:00', 'end' => '11:00'],
        ['day' => 'monday', 'start' => '14:00', 'end' => '16:00'],
        ['day' => 'tuesday', 'start' => '09:00', 'end' => '12:00'],
        ['day' => 'wednesday', 'start' => '13:00', 'end' => '14:00'],
        ['day' => 'wednesday', 'start' => '15:00', 'end' => '17:00'],
        ['day' => 'thursday', 'start' => '11:00', 'end' => '14:00'],
        ['day' => 'friday', 'start' => '10:00', 'end' => '12:00'],
        ['day' => 'friday', 'start' => '14:00', 'end' => '15:00'],
        ['day' => 'saturday', 'start' => '09:00', 'end' => '11:00'],
        ['day' => 'saturday', 'start' => '13:00', 'end' => '16:00'],
    ];

    public function handle(): int
    {
        $photographers = User::where('role', 'photographer')->orderBy('id')->get();
        $dryRun = (bool) $this->option('dry-run');

        if ($photographers->isEmpty()) {
            $this->warn('No photographers found.');
            return Command::SUCCESS;
        }

        $rows = [];
        $seeded = 0;

        foreach ($photographers as $index => $photographer) {
            $primary = $this->blockedWindows[$index % count($this->blockedWindows)];
            $secondary = $this->blockedWindows[($index + 3) % count($this->blockedWindows)];
            $windows = $index % 2 === 0 ? [$primary, $secondary] : [$primary];

            foreach ($windows as $window) {
                $rows[] = [
                    $photographer->id,
                    $photographer->name,
                    $window['day'],
                    $window['start'],
                    $window['end'],
                ];

                if (!$dryRun) {
                    PhotographerAvailability::updateOrCreate(
                        [
                            'photographer_id' => $photographer->id,
                            'date' => null,
                            'day_of_week' => $window['day'],
                            'start_time' => $window['start'],
                            'end_time' => $window['end'],
                        ],
                        ['status' => 'unavailable']
                    );
                }

                $seeded++;
            }
        }

        $this->table(['Photographer ID', 'Photographer', 'Day', 'Start', 'End'], $rows);
        $this->info(($dryRun ? 'Previewed' : 'Seeded') . " {$seeded} blocked windows.");

        return Command::SUCCESS;
    }
}
