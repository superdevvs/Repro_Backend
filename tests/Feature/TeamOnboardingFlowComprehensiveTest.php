<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalShootRequestedJob;
use App\Models\Service;
use App\Models\User;
use App\Services\Users\DashboardOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end coverage for every onboarding flow wired by the team-onboarding-flow feature:
 *   1. Registration                (POST /api/register)              source: registration
 *   2. Admin account creation      (POST /api/admin/users)           source: admin_account_created
 *   3. API import                  (POST /api/import/accounts)       source: api_import
 *   4. CSV import (artisan)        (accounts:import)                 source: artisan_import
 *   5. External booking            (POST /api/external/book-shoot)   source: external_booking
 *   6. Validation accept/reject    (PUT /api/profile)
 *   7. Version-based re-trigger    (service)
 *   8. Seeding command             (onboarding:seed-team)            source: seed_team_command
 *   9. Service core                (idempotence / passthrough / unrelated keys)
 */
class TeamOnboardingFlowComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
    }

    /** 1. Registration → client block, source 'registration'. */
    public function test_registration_applies_client_onboarding(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Reg Client',
            'email' => 'reg.client@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'reg.client@example.com')->firstOrFail();
        $block = $user->metadata['preferences']['clientDashboardOnboarding'] ?? null;

        $this->assertNotNull($block, 'registration should write client onboarding block');
        $this->assertTrue($block['eligible']);
        $this->assertSame(DashboardOnboardingService::VERSION_CLIENT, $block['version']);
        $this->assertSame('registration', $block['source']);
        $this->assertNotEmpty($block['createdAt']);
    }

    /** 2. Admin create → correct block per onboarded role; non-onboarded role gets none. */
    public function test_admin_create_applies_onboarding_per_role(): void
    {
        Sanctum::actingAs($this->adminUser());

        $roles = [
            'photographer' => 'photographerDashboardOnboarding',
            'salesRep' => 'salesRepDashboardOnboarding',
            'editing_manager' => 'editingManagerDashboardOnboarding',
            'editor' => 'editorDashboardOnboarding',
            'client' => 'clientDashboardOnboarding',
        ];

        foreach ($roles as $role => $key) {
            $email = 'admin.create.' . strtolower($role) . '@example.com';
            $response = $this->postJson('/api/admin/users', [
                'name' => "Admin Create {$role}",
                'email' => $email,
                'role' => $role,
                'account_status' => 'active',
            ]);
            $response->assertCreated();

            $user = User::where('email', $email)->firstOrFail();
            $block = $user->metadata['preferences'][$key] ?? null;
            $this->assertNotNull($block, "{$role}: block {$key} should exist");
            $this->assertTrue($block['eligible'], "{$role}: eligible");
            $this->assertSame('admin_account_created', $block['source'], "{$role}: source");
        }

        // Non-onboarded role -> no onboarding block of any kind.
        $this->postJson('/api/admin/users', [
            'name' => 'Admin Role',
            'email' => 'admin.role@example.com',
            'role' => 'admin',
            'account_status' => 'active',
        ])->assertCreated();
        $nonOnboarded = User::where('email', 'admin.role@example.com')->firstOrFail();
        $prefs = $nonOnboarded->metadata['preferences'] ?? [];
        foreach (array_values($roles) as $key) {
            $this->assertArrayNotHasKey($key, $prefs, "admin role must not have {$key}");
        }
    }

    /** 3. API import → source 'api_import'. */
    public function test_api_import_applies_onboarding(): void
    {
        Sanctum::actingAs($this->adminUser());

        $csv = "name,email,account type\n"
            . "Imp Photographer,imp.photographer@example.com,photographer\n"
            . "Imp Editor,imp.editor@example.com,editor\n";
        $file = UploadedFile::fake()->createWithContent('accounts.csv', $csv);

        $response = $this->postJson('/api/import/accounts', ['file' => $file]);
        $response->assertOk()->assertJsonPath('success', true);

        foreach ([
            'imp.photographer@example.com' => 'photographerDashboardOnboarding',
            'imp.editor@example.com' => 'editorDashboardOnboarding',
        ] as $email => $key) {
            $user = User::where('email', $email)->firstOrFail();
            $block = $user->metadata['preferences'][$key] ?? null;
            $this->assertNotNull($block, "{$email}: {$key} should exist");
            $this->assertTrue($block['eligible']);
            $this->assertSame('api_import', $block['source']);
        }
    }

    /** 4. CSV import artisan command → source 'artisan_import'. */
    public function test_csv_import_command_applies_onboarding(): void
    {
        $csv = "name,email,account type\n"
            . "Csv Photographer,csv.photographer@example.com,photographer\n"
            . "Csv Editor,csv.editor@example.com,editor\n";
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'onboarding_import_' . uniqid() . '.csv';
        file_put_contents($path, $csv);

        try {
            $this->artisan('accounts:import', ['file' => $path])->assertExitCode(0);
        } finally {
            @unlink($path);
        }

        foreach ([
            'csv.photographer@example.com' => 'photographerDashboardOnboarding',
            'csv.editor@example.com' => 'editorDashboardOnboarding',
        ] as $email => $key) {
            $user = User::where('email', $email)->firstOrFail();
            $block = $user->metadata['preferences'][$key] ?? null;
            $this->assertNotNull($block, "{$email}: {$key} should exist");
            $this->assertSame('artisan_import', $block['source']);
        }
    }

    /** 5. External booking → client block, source 'external_booking'. */
    public function test_external_booking_applies_client_onboarding(): void
    {
        Queue::fake([ProcessExternalShootRequestedJob::class]);
        config(['services.external_booking.api_key' => 'external-booking-test-key']);

        $service = Service::factory()->create(['name' => 'HDR Photos', 'price' => 185.00]);

        $response = $this->withHeaders(['X-API-Key' => 'external-booking-test-key'])
            ->postJson('/api/external/book-shoot', [
                'client_name' => 'External Onboard Client',
                'client_email' => 'external.onboard@example.com',
                'client_phone' => '2025550199',
                'address' => '901 External Ave',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'services' => [['id' => $service->id, 'quantity' => 1]],
                'preferred_date' => now()->addDays(2)->toDateString(),
                'preferred_time' => '10:30',
                'source' => 'lovable',
            ]);

        $response->assertCreated();
        $client = User::where('email', 'external.onboard@example.com')->firstOrFail();
        $block = $client->metadata['preferences']['clientDashboardOnboarding'] ?? null;
        $this->assertNotNull($block, 'external booking should write client onboarding block');
        $this->assertTrue($block['eligible']);
        $this->assertSame('external_booking', $block['source']);
    }

    /** 6a. Validation accepts well-formed role-aware blocks. */
    public function test_profile_validation_accepts_valid_blocks(): void
    {
        $client = User::factory()->create(['role' => 'client', 'account_status' => 'active']);
        Sanctum::actingAs($client);

        $response = $this->putJson('/api/profile', [
            'preferences' => [
                'photographerDashboardOnboarding' => [
                    'eligible' => true,
                    'version' => 1,
                    'lastStep' => 3,
                    'startedAt' => now()->toISOString(),
                ],
            ],
        ]);

        $response->assertOk();
    }

    /** 6b. Validation rejects malformed onboarding fields. */
    public function test_profile_validation_rejects_invalid_blocks(): void
    {
        $client = User::factory()->create(['role' => 'client', 'account_status' => 'active']);
        Sanctum::actingAs($client);

        $response = $this->putJson('/api/profile', [
            'preferences' => [
                'editorDashboardOnboarding' => [
                    'version' => 999,      // out of range (max 100)
                    'lastStep' => -5,      // out of range (min 0)
                    'eligible' => 'nope',  // not boolean
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** 7. Version-based re-trigger: lower stored version re-triggers and clears progress. */
    public function test_version_retrigger_clears_progress_and_bumps_version(): void
    {
        $service = app(DashboardOnboardingService::class);

        $metadata = [
            'preferences' => [
                'clientDashboardOnboarding' => [
                    'eligible' => false,
                    'version' => 0, // below current (1)
                    'createdAt' => '2020-01-01T00:00:00.000000Z',
                    'completedAt' => '2020-02-01T00:00:00.000000Z',
                    'dismissedAt' => '2020-02-02T00:00:00.000000Z',
                    'startedAt' => '2020-01-15T00:00:00.000000Z',
                    'lastStep' => 4,
                ],
            ],
        ];

        $result = $service->applyEligibility($metadata, 'client', null);
        $block = $result['preferences']['clientDashboardOnboarding'];

        $this->assertTrue($block['eligible'], 're-trigger sets eligible true');
        $this->assertSame(DashboardOnboardingService::VERSION_CLIENT, $block['version'], 'version bumped to current');
        $this->assertArrayNotHasKey('completedAt', $block);
        $this->assertArrayNotHasKey('dismissedAt', $block);
        $this->assertArrayNotHasKey('startedAt', $block);
        $this->assertArrayNotHasKey('lastStep', $block);
        $this->assertSame('2020-01-01T00:00:00.000000Z', $block['createdAt'], 'createdAt preserved');
    }

    /** 7b. Login re-evaluates eligibility: a stale stored version re-triggers onboarding. */
    public function test_login_retriggers_onboarding_for_stale_version(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'account_status' => 'active',
            'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
            'metadata' => [
                'preferences' => [
                    'clientDashboardOnboarding' => [
                        'eligible' => false,
                        'version' => 0, // below current (1)
                        'createdAt' => '2020-01-01T00:00:00.000000Z',
                        'completedAt' => '2020-02-01T00:00:00.000000Z',
                        'lastStep' => 4,
                    ],
                ],
            ],
        ]);

        $this->postJson('/api/login', [
            'email' => $client->email,
            'password' => 'secret123',
        ])->assertOk();

        $block = $client->fresh()->metadata['preferences']['clientDashboardOnboarding'];
        $this->assertTrue($block['eligible'], 'login re-trigger sets eligible true');
        $this->assertSame(DashboardOnboardingService::VERSION_CLIENT, $block['version'], 'version bumped to current');
        $this->assertSame('login', $block['source'], 'login is recorded as the re-trigger source');
        $this->assertArrayNotHasKey('completedAt', $block, 'progress cleared on re-trigger');
        $this->assertArrayNotHasKey('lastStep', $block);
        $this->assertSame('2020-01-01T00:00:00.000000Z', $block['createdAt'], 'createdAt preserved');
    }

    /** 7c. Login is a no-op for users already at the current onboarding version. */
    public function test_login_does_not_change_current_version_user(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'account_status' => 'active',
            'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
            'metadata' => [
                'preferences' => [
                    'clientDashboardOnboarding' => [
                        'eligible' => true,
                        'version' => DashboardOnboardingService::VERSION_CLIENT,
                        'createdAt' => '2024-01-01T00:00:00.000000Z',
                        'completedAt' => '2024-01-02T00:00:00.000000Z',
                    ],
                ],
            ],
        ]);
        $before = $client->fresh()->metadata;

        $this->postJson('/api/login', [
            'email' => $client->email,
            'password' => 'secret123',
        ])->assertOk();

        $this->assertEquals($before, $client->fresh()->metadata, 'login must not alter current-version onboarding');
    }

    /** 8. Seeding command applies eligibility to existing team users; dry-run writes nothing. */
    public function test_seed_command_applies_to_existing_team_users(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $editor = User::factory()->create(['role' => 'editor']);
        $adminUser = User::factory()->create(['role' => 'admin']);

        // Dry-run must not persist anything.
        $this->artisan('onboarding:seed-team', ['--dry-run' => true])->assertExitCode(0);
        $this->assertArrayNotHasKey(
            'photographerDashboardOnboarding',
            $photographer->fresh()->metadata['preferences'] ?? [],
            'dry-run must not write'
        );

        // Real run seeds the four roles.
        $this->artisan('onboarding:seed-team')->assertExitCode(0);

        foreach ([
            [$photographer, 'photographerDashboardOnboarding'],
            [$salesRep, 'salesRepDashboardOnboarding'],
            [$manager, 'editingManagerDashboardOnboarding'],
            [$editor, 'editorDashboardOnboarding'],
        ] as [$user, $key]) {
            $block = $user->fresh()->metadata['preferences'][$key] ?? null;
            $this->assertNotNull($block, "{$key} should be seeded");
            $this->assertTrue($block['eligible']);
            $this->assertSame('seed_team_command', $block['source']);
        }

        // Non-onboarded role untouched.
        $this->assertEmpty($adminUser->fresh()->metadata['preferences'] ?? []);
    }

    /** 9. Service core: idempotence, non-onboarded passthrough, unrelated keys preserved. */
    public function test_service_core_behaviors(): void
    {
        $service = app(DashboardOnboardingService::class);

        // Non-onboarded role -> unchanged.
        $input = ['preferences' => ['theme' => 'dark']];
        $this->assertSame($input, $service->applyEligibility($input, 'superadmin', 'x'));

        // Unrelated keys preserved + idempotence.
        $once = $service->applyEligibility(['preferences' => ['theme' => 'dark']], 'editor', 'api_import');
        $twice = $service->applyEligibility($once, 'editor', 'api_import');
        $this->assertSame('dark', $once['preferences']['theme'], 'unrelated key preserved');
        $this->assertSame($once, $twice, 'apply is idempotent at current version');
    }
}
