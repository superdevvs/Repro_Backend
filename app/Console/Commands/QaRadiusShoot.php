<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * QA-only helper for the photographer service-radius (Option B) assignment-path live test.
 *
 * Creates a minimal, coordinate-bearing shoot (with one service attached and NO per-item
 * scheduled_at, so the assignment availability check is a no-op) so the e2e suite can exercise the
 * REAL assignment endpoint (POST /shoots/{shoot}/assign-service-photographer →
 * AssignServicePhotographerAction) and observe the radius gate return 422 (outside) or proceed
 * (inside). Rows are tagged `created_by = system:qa_radius` for run-scoped cleanup.
 *
 * This command refuses to run in production.
 *
 *   php artisan qa:radius-shoot create --lat=38.8213 --lng=-77.1589   # outputs JSON
 *   php artisan qa:radius-shoot cleanup                                # deletes all QA radius shoots
 */
class QaRadiusShoot extends Command
{
    protected $signature = 'qa:radius-shoot {action : create|cleanup} {--lat=} {--lng=}';

    protected $description = 'QA-only: create/cleanup a coordinate-bearing shoot for the radius-enforcement assignment test.';

    private const TAG = 'system:qa_radius';
    private const SYSTEM_CLIENT_EMAIL = 'qa-radius-system@dashboard.local';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run qa:radius-shoot in production.');
            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'create' => $this->createShoot(),
            'cleanup' => $this->cleanup(),
            default => $this->invalidAction(),
        };
    }

    private function createShoot(): int
    {
        $lat = $this->option('lat');
        $lng = $this->option('lng');
        if (!is_numeric($lat) || !is_numeric($lng)) {
            $this->error('create requires numeric --lat and --lng.');
            return self::FAILURE;
        }

        $service = Service::query()->orderBy('id')->first();
        if (!$service) {
            $this->error('No Service rows exist to attach; seed a service first.');
            return self::FAILURE;
        }

        $client = User::firstOrCreate(
            ['email' => self::SYSTEM_CLIENT_EMAIL],
            [
                'name' => 'QA Radius System',
                'username' => 'qa-radius-system',
                'role' => 'client',
                'password' => bcrypt(bin2hex(random_bytes(16))),
            ],
        );

        $shoot = Shoot::create([
            'client_id' => $client->id,
            'address' => 'QA Radius Test Location',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22310',
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'status' => Shoot::STATUS_SCHEDULED,
            'base_quote' => 0,
            'tax_amount' => 0,
            'total_quote' => 0,
            'created_by' => self::TAG,
        ]);

        // Attach the service WITHOUT a per-item scheduled_at so the availability check is skipped
        // and only the radius gate decides eligibility.
        $shoot->services()->attach($service->id, [
            'price' => 0,
            'quantity' => 1,
        ]);

        $this->line(json_encode([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'client_id' => $client->id,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ]));

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        $shoots = Shoot::where('created_by', self::TAG)->get();
        $count = 0;
        foreach ($shoots as $shoot) {
            $shoot->services()->detach();
            $shoot->delete();
            $count++;
        }
        $this->line(json_encode(['deleted' => $count]));
        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action; use "create" or "cleanup".');
        return self::FAILURE;
    }
}
