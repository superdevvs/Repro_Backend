<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SeedPhotographerTestAddresses extends Command
{
    protected $signature = 'photographers:seed-test-addresses {--dry-run : Preview updates without writing to the database}';

    protected $description = 'Seed photographer addresses near 6424 Vale Street, Alexandria, VA for distance testing';

    private array $nearbyAddresses = [
        ['address' => '6424 Vale Street', 'city' => 'Alexandria', 'state' => 'VA', 'zip' => '22312', 'latitude' => 38.8213, 'longitude' => -77.1589],
        ['address' => '4600 Duke Street', 'city' => 'Alexandria', 'state' => 'VA', 'zip' => '22304', 'latitude' => 38.8139, 'longitude' => -77.1110],
        ['address' => '7200 Columbia Pike', 'city' => 'Annandale', 'state' => 'VA', 'zip' => '22003', 'latitude' => 38.8327, 'longitude' => -77.1940],
        ['address' => '6500 Springfield Mall', 'city' => 'Springfield', 'state' => 'VA', 'zip' => '22150', 'latitude' => 38.7748, 'longitude' => -77.1751],
        ['address' => '12000 Government Center Parkway', 'city' => 'Fairfax', 'state' => 'VA', 'zip' => '22035', 'latitude' => 38.8531, 'longitude' => -77.3573],
        ['address' => '2100 Clarendon Boulevard', 'city' => 'Arlington', 'state' => 'VA', 'zip' => '22201', 'latitude' => 38.8894, 'longitude' => -77.0860],
        ['address' => '300 Park Avenue', 'city' => 'Falls Church', 'state' => 'VA', 'zip' => '22046', 'latitude' => 38.8823, 'longitude' => -77.1711],
        ['address' => '1420 Spring Hill Road', 'city' => 'McLean', 'state' => 'VA', 'zip' => '22102', 'latitude' => 38.9290, 'longitude' => -77.2410],
        ['address' => '1800 Tysons Boulevard', 'city' => 'Tysons', 'state' => 'VA', 'zip' => '22102', 'latitude' => 38.9187, 'longitude' => -77.2220],
        ['address' => '11900 Market Street', 'city' => 'Reston', 'state' => 'VA', 'zip' => '20190', 'latitude' => 38.9586, 'longitude' => -77.3580],
        ['address' => '1 County Complex Court', 'city' => 'Woodbridge', 'state' => 'VA', 'zip' => '22192', 'latitude' => 38.6800, 'longitude' => -77.3520],
        ['address' => '9201 Center Street', 'city' => 'Manassas', 'state' => 'VA', 'zip' => '20110', 'latitude' => 38.7509, 'longitude' => -77.4753],
        ['address' => '100 Maryland Avenue', 'city' => 'Rockville', 'state' => 'MD', 'zip' => '20850', 'latitude' => 39.0840, 'longitude' => -77.1530],
        ['address' => '1 Veterans Place', 'city' => 'Silver Spring', 'state' => 'MD', 'zip' => '20910', 'latitude' => 38.9970, 'longitude' => -77.0250],
        ['address' => '4550 Montgomery Avenue', 'city' => 'Bethesda', 'state' => 'MD', 'zip' => '20814', 'latitude' => 38.9847, 'longitude' => -77.0957],
        ['address' => '1350 Pennsylvania Avenue NW', 'city' => 'Washington', 'state' => 'DC', 'zip' => '20004', 'latitude' => 38.8951, 'longitude' => -77.0364],
        ['address' => '25 West Market Street', 'city' => 'Leesburg', 'state' => 'VA', 'zip' => '20176', 'latitude' => 39.1162, 'longitude' => -77.5636],
        ['address' => '601 Caroline Street', 'city' => 'Fredericksburg', 'state' => 'VA', 'zip' => '22401', 'latitude' => 38.3032, 'longitude' => -77.4605],
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

        foreach ($photographers as $index => $photographer) {
            $location = $this->nearbyAddresses[$index % count($this->nearbyAddresses)];
            $metadata = $photographer->metadata;
            if (!is_array($metadata)) {
                $metadata = [];
            }

            $metadata['address'] = $location['address'];
            $metadata['homeAddress'] = $location['address'];
            $metadata['city'] = $location['city'];
            $metadata['state'] = $location['state'];
            $metadata['zip'] = $location['zip'];
            $metadata['zipcode'] = $location['zip'];
            $metadata['latitude'] = $location['latitude'];
            $metadata['longitude'] = $location['longitude'];
            $metadata['lat'] = $location['latitude'];
            $metadata['lng'] = $location['longitude'];

            if (!$dryRun) {
                $photographer->forceFill([
                    'address' => $location['address'],
                    'city' => $location['city'],
                    'state' => $location['state'],
                    'zip' => $location['zip'],
                    'metadata' => $metadata,
                ])->save();
            }

            $rows[] = [
                $photographer->id,
                $photographer->name,
                $location['address'],
                $location['city'],
                $location['state'],
                $location['zip'],
                $location['latitude'],
                $location['longitude'],
            ];
        }

        $this->table(['ID', 'Name', 'Address', 'City', 'State', 'Zip', 'Lat', 'Lng'], $rows);
        $this->info(($dryRun ? 'Previewed' : 'Seeded') . " {$photographers->count()} photographer addresses near 6424 Vale Street, Alexandria, VA.");

        return Command::SUCCESS;
    }
}
