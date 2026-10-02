<?php

namespace App\Services\Photographers;

use App\Models\Shoot;
use App\Models\User;
use App\Services\AddressLookupService;
use App\Services\Schedule\ScheduleInstantResolver;
use App\Services\Shoots\ShootDurationResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Builds fail-open map payload for the book/edit photographer picker.
 * Missing coords / drive data → nulls; callers keep listing photographers.
 */
class PhotographerPickerMapBuilder
{
    public function __construct(
        private AddressLookupService $distances,
        private ScheduleInstantResolver $instants,
        private ShootDurationResolver $durations,
    ) {}

    /**
     * @param  array{address?:string,city?:string,state?:string,zip?:string,latitude?:float|int|string|null,longitude?:float|int|string|null}  $job
     * @param  array{network_allowed?:bool,deadline?:float}|null  $lookupPolicy
     * @return array{
     *   home: array{lat:float,lng:float}|null,
     *   job: array{lat:float,lng:float}|null,
     *   last_shoot: array{shoot_id:int,address:?string,lat:?float,lng:?float,ends_at:string}|null,
     *   next_shoot: array{shoot_id:int,address:?string,lat:?float,lng:?float,starts_at:string}|null,
     *   drive_minutes: array{last_to_job:?int,job_to_next:?int,source:?string,is_estimate:bool}|null,
     *   miles_to_job: float|null,
     *   travel_risk: array{
     *     last_to_job: ?string,
     *     job_to_next: ?string,
     *     buffer_minutes: int,
     *     slack_minutes: array{last_to_job:?int,job_to_next:?int}
     *   }|null
     * }
     */
    public function build(
        User $photographer,
        array $job,
        ?CarbonInterface $jobStart,
        int $jobDurationMinutes,
        $shootsOnDate,
        ?float $knownMilesToJob = null,
        ?array $knownLastToJobDistance = null,
        ?array $lookupPolicy = null,
        bool $lastLegAlreadyResolved = false,
    ): array {
        $home = $this->homeCoords($photographer);
        $jobCoords = $this->coords($job['latitude'] ?? null, $job['longitude'] ?? null);

        $lastShoot = null;
        $nextShoot = null;
        $last = null;
        $next = null;
        if ($jobStart !== null) {
            $jobEnd = $jobStart->copy()->addMinutes(max(1, $jobDurationMinutes));
            [$lastShoot, $lastEndsAt] = $this->findLastShoot($shootsOnDate, $jobStart);
            [$nextShoot, $nextStartsAt] = $this->findNextShoot($shootsOnDate, $jobEnd);
            $last = $lastShoot ? $this->shootPin($lastShoot, 'ends_at', $lastEndsAt) : null;
            $next = $nextShoot ? $this->shootPin($nextShoot, 'starts_at', $nextStartsAt) : null;
        }

        $lastLeg = $knownLastToJobDistance;
        // Avoid a second Distance Matrix hop when for-booking already resolved origin→job.
        if ($lastLeg === null && ! $lastLegAlreadyResolved) {
            if ($lastShoot !== null && (($last['lat'] ?? null) !== null || $this->hasAddressParts($lastShoot))) {
                $lastLeg = $this->legDistance(
                    $this->shootAddressPayload($lastShoot),
                    $job,
                    $lookupPolicy
                );
            } elseif ($lastShoot === null && ($home !== null || $this->hasHomeAddress($photographer))) {
                $lastLeg = $this->legDistance(
                    $this->homeAddressPayload($photographer, $home),
                    $job,
                    $lookupPolicy
                );
            }
        }

        $nextLeg = null;
        if ($nextShoot !== null) {
            $nextLeg = $this->legDistance($job, $this->shootAddressPayload($nextShoot), $lookupPolicy);
        }

        $miles = $knownMilesToJob;
        if ($miles === null && is_array($lastLeg) && isset($lastLeg['distance_value'])) {
            $miles = round(((float) $lastLeg['distance_value']) / 1609.34, 1);
        }

        $lastDrive = $this->minutesFromLeg($lastLeg);
        $nextDrive = $this->minutesFromLeg($nextLeg);
        $anyEstimate = (bool) (($lastLeg['is_estimate'] ?? true) || ($nextLeg['is_estimate'] ?? true));
        $source = null;
        if ($lastDrive !== null || $nextDrive !== null) {
            $sources = array_values(array_unique(array_filter([
                $lastLeg['source'] ?? null,
                $nextLeg['source'] ?? null,
            ])));
            $source = count($sources) === 1
                ? $sources[0]
                : ($anyEstimate ? 'estimate' : 'google_distance_matrix');
        }

        $driveMinutes = ($lastDrive === null && $nextDrive === null)
            ? null
            : [
                'last_to_job' => $lastDrive,
                'job_to_next' => $nextDrive,
                'source' => $source,
                'is_estimate' => $anyEstimate,
            ];

        $buffer = max(15, (int) config('availability.buffer_time_minutes', 15));
        $travelRisk = null;
        if ($jobStart !== null && ($lastDrive !== null || $nextDrive !== null)) {
            $lastSlack = null;
            $nextSlack = null;
            if ($last !== null && $lastDrive !== null && ! empty($last['ends_at'])) {
                // Civil-clock gap (matches for-booking last/next selection) — avoid
                // mixing app-TZ job start timestamps with offset-aware shoot instants.
                $gap = $this->civilGapMinutes(Carbon::parse($last['ends_at']), $jobStart);
                $lastSlack = $gap - $lastDrive;
            }
            if ($next !== null && $nextDrive !== null && ! empty($next['starts_at'])) {
                $jobEnd = $jobStart->copy()->addMinutes(max(1, $jobDurationMinutes));
                $gap = $this->civilGapMinutes($jobEnd, Carbon::parse($next['starts_at']));
                $nextSlack = $gap - $nextDrive;
            }
            $travelRisk = [
                'last_to_job' => $this->riskLabel($lastSlack, $buffer),
                'job_to_next' => $this->riskLabel($nextSlack, $buffer),
                'buffer_minutes' => $buffer,
                'slack_minutes' => [
                    'last_to_job' => $lastSlack,
                    'job_to_next' => $nextSlack,
                ],
            ];
        }

        return [
            'home' => $home,
            'job' => $jobCoords,
            'last_shoot' => $last,
            'next_shoot' => $next,
            'drive_minutes' => $driveMinutes,
            'miles_to_job' => $miles,
            'travel_risk' => $travelRisk,
        ];
    }

    /** Response-level job pin echoed once for FE route drawing. */
    public function jobPin(array $job): array
    {
        $coords = $this->coords($job['latitude'] ?? null, $job['longitude'] ?? null);

        return [
            'lat' => $coords['lat'] ?? null,
            'lng' => $coords['lng'] ?? null,
            'address' => $job['address'] ?? null,
            'city' => $job['city'] ?? null,
            'state' => $job['state'] ?? null,
            'zip' => $job['zip'] ?? null,
        ];
    }

    private function homeCoords(User $photographer): ?array
    {
        $metadata = is_string($photographer->metadata)
            ? json_decode($photographer->metadata, true)
            : ($photographer->metadata ?? []);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        return $this->coords(
            $metadata['latitude'] ?? $metadata['lat'] ?? null,
            $metadata['longitude'] ?? $metadata['lng'] ?? null
        );
    }

    private function coords(mixed $lat, mixed $lng): ?array
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function findLastShoot($shootsOnDate, CarbonInterface $jobStart): array
    {
        $best = null;
        $bestEnd = null;
        foreach ($shootsOnDate as $shoot) {
            $start = $this->instants->forShoot($shoot);
            if (! $start) {
                continue;
            }
            $duration = $this->durations->forShoot($shoot);
            $end = $start->copy()->addMinutes($duration);
            if ($end->format('Y-m-d H:i:s') > $jobStart->format('Y-m-d H:i:s')) {
                continue;
            }
            if ($bestEnd === null || $end->format('Y-m-d H:i:s') > $bestEnd->format('Y-m-d H:i:s')) {
                $best = $shoot;
                $bestEnd = $end;
            }
        }

        return [$best, $bestEnd];
    }

    private function findNextShoot($shootsOnDate, CarbonInterface $jobEnd): array
    {
        $best = null;
        $bestStart = null;
        foreach ($shootsOnDate as $shoot) {
            $start = $this->instants->forShoot($shoot);
            if (! $start) {
                continue;
            }
            if ($start->format('Y-m-d H:i:s') < $jobEnd->format('Y-m-d H:i:s')) {
                continue;
            }
            if ($bestStart === null || $start->format('Y-m-d H:i:s') < $bestStart->format('Y-m-d H:i:s')) {
                $best = $shoot;
                $bestStart = $start;
            }
        }

        return [$best, $bestStart];
    }

    private function shootPin(Shoot $shoot, string $timeKey, CarbonInterface $when): array
    {
        $coords = $this->coords($shoot->latitude, $shoot->longitude);
        $address = trim(implode(', ', array_filter([
            $shoot->property_address ?? $shoot->address ?? null,
            $shoot->city ?? null,
            $shoot->state ?? null,
            $shoot->zip ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '')));

        return [
            'shoot_id' => (int) $shoot->id,
            'address' => $address !== '' ? $address : null,
            'lat' => $coords['lat'] ?? null,
            'lng' => $coords['lng'] ?? null,
            $timeKey => $when->toIso8601String(),
        ];
    }

    private function shootAddressPayload(?Shoot $shoot): array
    {
        if (! $shoot) {
            return [];
        }

        return [
            'address' => $shoot->property_address ?? $shoot->address ?? '',
            'city' => $shoot->city ?? '',
            'state' => $shoot->state ?? '',
            'zip' => $shoot->zip ?? '',
            'latitude' => $shoot->latitude,
            'longitude' => $shoot->longitude,
        ];
    }

    private function homeAddressPayload(User $photographer, ?array $home): array
    {
        $metadata = is_string($photographer->metadata)
            ? json_decode($photographer->metadata, true)
            : ($photographer->metadata ?? []);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        return [
            'address' => $photographer->address ?? $metadata['address'] ?? $metadata['homeAddress'] ?? '',
            'city' => $photographer->city ?? $metadata['city'] ?? '',
            'state' => $photographer->state ?? $metadata['state'] ?? '',
            'zip' => $photographer->zip ?? $metadata['zip'] ?? $metadata['zipcode'] ?? '',
            'latitude' => $home['lat'] ?? null,
            'longitude' => $home['lng'] ?? null,
        ];
    }

    private function hasHomeAddress(User $photographer): bool
    {
        $payload = $this->homeAddressPayload($photographer, null);

        return ($payload['address'] !== '') || ($payload['city'] !== '' && $payload['state'] !== '');
    }

    private function hasAddressParts(?Shoot $shoot): bool
    {
        if (! $shoot) {
            return false;
        }
        $payload = $this->shootAddressPayload($shoot);

        return ($payload['address'] !== '') || ($payload['city'] !== '' && $payload['state'] !== '');
    }

    private function legDistance(array $origin, array $destination, ?array $lookupPolicy): ?array
    {
        $hasOrigin = ($origin['address'] ?? '') || (($origin['city'] ?? '') && ($origin['state'] ?? ''))
            || (is_numeric($origin['latitude'] ?? null) && is_numeric($origin['longitude'] ?? null));
        $hasDest = ($destination['address'] ?? '') || (($destination['city'] ?? '') && ($destination['state'] ?? ''))
            || (is_numeric($destination['latitude'] ?? null) && is_numeric($destination['longitude'] ?? null));
        if (! $hasOrigin || ! $hasDest) {
            return null;
        }
        try {
            return $this->distances->getDistance($origin, $destination, $lookupPolicy);
        } catch (\Throwable) {
            return null;
        }
    }

    private function minutesFromLeg(?array $leg): ?int
    {
        if (! is_array($leg) || ! isset($leg['duration_value']) || ! is_numeric($leg['duration_value'])) {
            return null;
        }

        return (int) max(0, (int) round(((float) $leg['duration_value']) / 60));
    }


    /** Same-day civil minutes between two clock values (timezone-agnostic). */
    private function civilGapMinutes(CarbonInterface $from, CarbonInterface $to): int
    {
        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');
        $dayDelta = (int) (new \DateTimeImmutable($fromDay))->diff(new \DateTimeImmutable($toDay))->format('%r%a');
        $fromMin = ((int) $from->format('G')) * 60 + (int) $from->format('i');
        $toMin = ((int) $to->format('G')) * 60 + (int) $to->format('i');

        return ($dayDelta * 1440) + ($toMin - $fromMin);
    }

    private function riskLabel(?int $slack, int $buffer): ?string
    {
        if ($slack === null) {
            return null;
        }
        if ($slack < 0) {
            return 'late';
        }
        if ($slack <= $buffer) {
            return 'tight';
        }

        return 'early';
    }
}
