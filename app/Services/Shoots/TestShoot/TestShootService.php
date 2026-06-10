<?php

namespace App\Services\Shoots\TestShoot;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ServiceAreaMatcher;
use App\Services\Shoots\ShootDateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Test_Shoot generator/simulator (Req 10.7-10.11).
 *
 * Lets an Admin create a region/state/area-scoped Shoot, preview which
 * photographers are eligible for it, and assign one. The simulator is
 * deliberately built on top of the production matching and date services so the
 * Test_Shoot exercises the same code paths as a real shoot.
 *
 * Implementation notes:
 * - Test shoots REUSE the existing {@see Shoot::SHOOT_TYPE_INTERNAL_TEST}
 *   classification on the `shoot_type` column rather than introducing an
 *   `is_test` flag (Req 10 / task 10.4 reuse note).
 * - Region/state/area scoping is recorded on the existing
 *   `service_area_kind` and `service_area_value` columns added in task 1.1.
 * - The local calendar day is computed by {@see ShootDateService::localCalendarDate()}
 *   so the same timezone-authoritative logic backs storage and display
 *   (Req 9 / 10.11).
 * - Eligibility delegates to the pure {@see ServiceAreaMatcher} so preview
 *   and assignment share one match path (Req 10.2 / 10.8).
 */
class TestShootService
{
    /**
     * Email of the sentinel system "client" used to satisfy the
     * shoots.client_id NOT NULL constraint for simulator rows. The Test_Shoot
     * has no real client; this account exists solely to keep the simulator
     * self-contained without polluting real client data.
     */
    private const SYSTEM_CLIENT_EMAIL = 'test-shoot-system@dashboard.local';

    public function __construct(
        private readonly ServiceAreaMatcher $matcher,
        private readonly ShootDateService $dates,
    ) {
    }

    /**
     * Create a Test_Shoot scoped to a region/state/area at a specific instant
     * in the given IANA timezone (Req 10.7, 10.11).
     *
     * The returned Shoot is persisted with `shoot_type = internal_test`,
     * the `service_area_kind`/`service_area_value` scope, and a
     * timezone-correct `scheduled_at` and `scheduled_date`.
     *
     * @param  array{kind: string, value: string}  $area
     */
    public function create(array $area, CarbonImmutable $when, string $timezone): Shoot
    {
        // The local calendar day is computed in the region timezone, but
        // `scheduled_at` is persisted as the absolute instant — letting
        // Eloquent's datetime cast round-trip through UTC without shifting
        // the day on read (Req 9 / 10.11). Reuse ShootDateService's
        // authoritative local-day logic via an in-memory reference Shoot so
        // storage and display agree on which calendar day this Test_Shoot
        // belongs to.
        $reference = new Shoot();
        $reference->scheduled_at = $when;
        $reference->timezone = $timezone;
        $scheduledDate = $this->dates->localCalendarDate($reference);

        return Shoot::create([
            // REUSE the existing internal-test classification (no `is_test` column).
            'shoot_type'         => Shoot::SHOOT_TYPE_INTERNAL_TEST,

            // Region/state/area scoping for the simulator (Req 10.7).
            'service_area_kind'  => $area['kind'],
            'service_area_value' => $area['value'],

            // Timezone-authoritative storage (Req 9 / 10.11). `scheduled_at`
            // stays the absolute instant (Eloquent persists datetimes as UTC),
            // while `scheduled_date` carries the local calendar day in the
            // region's timezone — the source of truth for "which day this
            // Test_Shoot belongs to".
            'timezone'           => $timezone,
            'scheduled_at'       => $when,
            'scheduled_date'     => $scheduledDate,

            // Surface the Test_Shoot in the schedule like a real scheduled shoot.
            'status'             => Shoot::STATUS_SCHEDULED,

            // Test_Shoots carry no real client / property; populate the
            // remaining NOT NULL columns with neutral placeholders so the
            // simulator does not pollute real shoot data. `client_id` points
            // at the sentinel system account to satisfy the FK / NOT NULL
            // constraint without coupling the simulator to a real client.
            'client_id'          => $this->resolveSystemClientId(),
            'address'            => $this->placeholderAddress($area),
            'city'               => '',
            'state'              => $area['kind'] === 'state' ? $area['value'] : '',
            'zip'                => '',
            'base_quote'         => 0,
            'tax_amount'         => 0,
            'total_quote'        => 0,
            'created_by'         => 'system:test_shoot',
        ]);
    }

    /**
     * Find or create the sentinel system "client" used by the Test_Shoot
     * simulator. The account is locked (random password, no email
     * verification) and exists only to satisfy the shoots.client_id NOT NULL
     * constraint without referencing a real customer.
     */
    private function resolveSystemClientId(): int
    {
        $client = User::firstOrCreate(
            ['email' => self::SYSTEM_CLIENT_EMAIL],
            [
                'name'     => 'Test_Shoot System',
                'username' => 'test-shoot-system',
                'role'     => 'client',
                // Password is unguessable + locked; this account is not for sign-in.
                'password' => bcrypt(bin2hex(random_bytes(16))),
            ],
        );

        return (int) $client->getKey();
    }

    /**
     * Photographers eligible for a Test_Shoot are those whose service-area
     * assignments match the Test_Shoot's (kind, value) — delegates to the
     * pure matcher so preview and assignment share one match path
     * (Req 10.2 / 10.8).
     *
     * @param  Collection<int, User>  $photographers  with `serviceAreas` loaded
     * @return Collection<int, User>
     */
    public function eligiblePhotographers(Shoot $testShoot, Collection $photographers): Collection
    {
        return $this->matcher->match($photographers, [
            'kind'  => (string) $testShoot->service_area_kind,
            'value' => (string) $testShoot->service_area_value,
        ]);
    }

    /**
     * Assign a Photographer to a Test_Shoot and persist the link (Req 10.9).
     */
    public function assign(Shoot $testShoot, User $photographer): void
    {
        $testShoot->update(['photographer_id' => $photographer->id]);
    }

    /**
     * Compose a human-readable address-line placeholder for a Test_Shoot so an
     * admin scanning a list can identify the simulator scope at a glance.
     *
     * @param  array{kind: string, value: string}  $area
     */
    private function placeholderAddress(array $area): string
    {
        return 'Test_Shoot ('.$area['kind'].': '.$area['value'].')';
    }
}
