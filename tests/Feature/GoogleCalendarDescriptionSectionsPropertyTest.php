<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Feature: google-calendar-sync-upgrade, Property 2: Description omits empty
 * phone/email lines but always renders named sections.
 *
 * Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.7, 3.8, 3.9, 3.10
 *
 * For any shoot, the description produced by
 * GoogleCalendarEventPayloadBuilder::build() (the `description` key):
 *
 *   (a) contains a "Phone:" line iff the client phone (client `phone`, falling
 *       back to `phonenumber`) is non-empty (Req 3.2, 3.3);
 *   (b) contains an "Email:" line iff the client email is non-empty
 *       (Req 3.4, 3.5);
 *   (c) always renders the "Shoot Notes:", "Property Access:",
 *       "Arrival Instructions:", and "On-Site Contact:" named sections
 *       (Req 3.7, 3.8, 3.9, 3.10);
 *   (d) renders "Not provided" for each named section whose derived value is
 *       empty — for On-Site Contact this happens only when no client display
 *       name can be derived (Req 3.7, 3.8, 3.9, 3.10);
 *   (e) always starts with the client display name as its first line (Req 3.1).
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so this test follows the deterministic-generator convention used by
 * the rest of the suite (see GoogleCalendarTitlePropertyTest): a seeded PRNG
 * produces well over 100 randomized shoot states spanning every phone channel
 * (phone / phonenumber / neither), email presence/absence, the client
 * name/company/empty display-name fallbacks, and every combination of
 * shoot_notes / notes / photographer_notes presence (which drive the derived
 * Property Access / Arrival Instructions sections). External Google Calendar
 * HTTP is mocked (the builder issues none, but the service is bound to a mock
 * and stray HTTP is blocked).
 */
class GoogleCalendarDescriptionSectionsPropertyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /** Property iterations — comfortably above the mandated 100. */
    private const ITERATIONS = 150;

    /** Fixed seed so any counterexample reproduces deterministically. */
    private const SEED = 2_00_02;

    protected function setUp(): void
    {
        parent::setUp();

        // The builder performs pure string/array construction and makes no HTTP
        // calls, but the task mandates the Google Calendar transport is mocked
        // and no live HTTP escapes. Bind a mock service and block stray calls.
        $this->app->instance(GoogleCalendarService::class, Mockery::mock(GoogleCalendarService::class));
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Feature: google-calendar-sync-upgrade, Property 2: Description omits empty
     * phone/email lines but always renders named sections.
     *
     * Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.7, 3.8, 3.9, 3.10
     */
    public function test_description_omits_empty_contact_lines_and_always_renders_named_sections(): void
    {
        mt_srand(self::SEED);

        $builder = app(GoogleCalendarEventPayloadBuilder::class);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // --- Client display name: 0 name, 1 company fallback, 2 both empty.
            $nameCase = mt_rand(0, 2);
            $nameToken = 'ClientNm' . $i . 'Qz';
            $companyToken = 'ClientCo' . $i . 'Qz';
            [$clientName, $clientCompany] = match ($nameCase) {
                0 => [$nameToken, $companyToken],
                1 => ['', $companyToken],
                default => ['', ''],
            };
            // First description line uses name -> company -> "Client".
            $expectedDisplayName = $clientName !== '' ? $clientName : ($clientCompany !== '' ? $clientCompany : 'Client');
            // On-Site Contact uses name -> company, with NO "Client" fallback.
            $onSiteName = $clientName !== '' ? $clientName : $clientCompany; // '' when both empty

            // --- Phone channel: 0 phone set, 1 phonenumber only, 2 neither.
            $phoneCase = mt_rand(0, 2);
            $phoneToken = 'PhoneTok' . $i . 'Qz';
            $phonenumberToken = 'PhnumTok' . $i . 'Qz';
            [$phone, $phonenumber, $expectedPhone] = match ($phoneCase) {
                0 => [$phoneToken, $phonenumberToken, $phoneToken],     // phone wins
                1 => [null, $phonenumberToken, $phonenumberToken],      // phonenumber fallback
                default => [null, null, ''],                            // no phone
            };

            // --- Email presence.
            $emailPresent = mt_rand(0, 1) === 1;
            $email = $emailPresent ? ('descprop' . $i . '@example.test') : '';
            $expectedEmail = $email;

            // The users.email column is NOT NULL + UNIQUE, so every persisted
            // client needs a real distinct address. To exercise the missing
            // phone/email/name branches we override the contact fields on the
            // in-memory client relation (no save) and attach it directly to the
            // shoot, which keeps build()'s loadMissing() from reloading the
            // database copy. This lets the generator span empty values the
            // schema would otherwise reject.
            $client = User::factory()->create([
                'role' => 'client',
            ]);
            $client->name = $clientName;
            $client->company_name = $clientCompany;
            $client->phone = $phone ?? '';
            $client->phonenumber = $phonenumber ?? '';
            $client->email = $email;

            $photographer = User::factory()->photographer()->create([
                'timezone' => 'America/New_York',
            ]);

            // --- Notes channels (drive Shoot Notes / Property Access / Arrival).
            $shootNotesPresent = mt_rand(0, 1) === 1;
            $notesPresent = mt_rand(0, 1) === 1;
            $photographerNotesPresent = mt_rand(0, 1) === 1;

            $shootNotesToken = 'ShootNotesTok' . $i . 'Qz';
            $notesToken = 'NotesTok' . $i . 'Qz';
            $photographerNotesToken = 'PhotogNotesTok' . $i . 'Qz';

            $shootNotes = $shootNotesPresent ? $shootNotesToken : null;
            $notes = $notesPresent ? $notesToken : null;
            $photographerNotes = $photographerNotesPresent ? $photographerNotesToken : null;

            // customerFacingNotes = shoot_notes ?: notes (trimmed).
            $customerFacing = $shootNotesPresent ? $shootNotesToken : ($notesPresent ? $notesToken : '');

            $scheduledAt = now()->addDays(mt_rand(1, 30))->setTime(mt_rand(7, 18), [0, 15, 30, 45][mt_rand(0, 3)]);

            $shoot = Shoot::factory()->create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'status' => Shoot::STATUS_SCHEDULED,
                'workflow_status' => Shoot::STATUS_SCHEDULED,
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledAt->toDateString(),
                'time' => $scheduledAt->format('H:i'),
                'shoot_notes' => $shootNotes,
                'notes' => $notes,
                'photographer_notes' => $photographerNotes,
            ]);

            // Attach the in-memory client (with overridden contact fields) and an
            // empty services collection so build()'s loadMissing() does not reload
            // and clobber the generated contact state.
            $shoot->setRelation('client', $client);
            $shoot->setRelation('services', collect());

            $payload = $builder->build($shoot, $photographer);
            $description = $payload['description'];

            $context = sprintf(
                'iteration %d, nameCase=%d, phoneCase=%d, emailPresent=%s, shootNotes=%s, notes=%s, photogNotes=%s',
                $i,
                $nameCase,
                $phoneCase,
                $emailPresent ? 'y' : 'n',
                $shootNotesPresent ? 'y' : 'n',
                $notesPresent ? 'y' : 'n',
                $photographerNotesPresent ? 'y' : 'n'
            );

            $this->assertIsString($description, "description must be a string. {$context}");

            // (e) The first line is always the client display name (Req 3.1).
            $firstLine = explode("\n", $description)[0];
            $this->assertSame(
                $expectedDisplayName,
                $firstLine,
                "[e] first line must be the client display name. {$context}"
            );

            // (a) "Phone:" line present iff client phone is non-empty (Req 3.2, 3.3).
            $hasPhoneLine = (bool) preg_match('/^Phone: .+$/m', $description);
            if ($expectedPhone !== '') {
                $this->assertTrue($hasPhoneLine, "[a] Phone line must be present. {$context}");
                $this->assertStringContainsString(
                    "Phone: {$expectedPhone}",
                    $description,
                    "[a] Phone line must carry the resolved phone. {$context}"
                );
            } else {
                $this->assertFalse($hasPhoneLine, "[a] Phone line must be omitted when no phone. {$context}");
            }

            // (b) "Email:" line present iff client email is non-empty (Req 3.4, 3.5).
            $hasEmailLine = (bool) preg_match('/^Email: .+$/m', $description);
            if ($expectedEmail !== '') {
                $this->assertTrue($hasEmailLine, "[b] Email line must be present. {$context}");
                $this->assertStringContainsString(
                    "Email: {$expectedEmail}",
                    $description,
                    "[b] Email line must carry the client email. {$context}"
                );
            } else {
                $this->assertFalse($hasEmailLine, "[b] Email line must be omitted when no email. {$context}");
            }

            // (c) Named sections are ALWAYS present (Req 3.7, 3.8, 3.9, 3.10).
            $shootNotesBody = $this->sectionBody($description, 'Shoot Notes:');
            $propertyAccessBody = $this->sectionBody($description, 'Property Access:');
            $arrivalBody = $this->sectionBody($description, 'Arrival Instructions:');
            $onSiteBody = $this->sectionBody($description, 'On-Site Contact:');

            $this->assertNotNull($shootNotesBody, "[c] Shoot Notes section must be present. {$context}");
            $this->assertNotNull($propertyAccessBody, "[c] Property Access section must be present. {$context}");
            $this->assertNotNull($arrivalBody, "[c] Arrival Instructions section must be present. {$context}");
            $this->assertNotNull($onSiteBody, "[c] On-Site Contact section must be present. {$context}");

            // (d) Each named section renders its derived value or "Not provided".
            $expectedShootNotes = $customerFacing !== '' ? $customerFacing : 'Not provided';
            $this->assertSame(
                $expectedShootNotes,
                $shootNotesBody,
                "[d] Shoot Notes body must render value or 'Not provided'. {$context}"
            );

            // Property Access derives from the customer-facing note text.
            $expectedPropertyAccess = $customerFacing !== '' ? $customerFacing : 'Not provided';
            $this->assertSame(
                $expectedPropertyAccess,
                $propertyAccessBody,
                "[d] Property Access body must render derived value or 'Not provided'. {$context}"
            );

            // Arrival Instructions: photographer_notes, else customer-facing, else "Not provided".
            $expectedArrival = $photographerNotesPresent
                ? $photographerNotesToken
                : ($customerFacing !== '' ? $customerFacing : 'Not provided');
            $this->assertSame(
                $expectedArrival,
                $arrivalBody,
                "[d] Arrival Instructions body must render derived value or 'Not provided'. {$context}"
            );

            // On-Site Contact: client name + optional (phone, email); "Not provided"
            // only when no client display name can be derived.
            if ($onSiteName === '') {
                $expectedOnSite = 'Not provided';
            } else {
                $details = array_values(array_filter([$expectedPhone, $expectedEmail], static fn ($v) => $v !== ''));
                $expectedOnSite = $details === []
                    ? $onSiteName
                    : $onSiteName . ' (' . implode(', ', $details) . ')';
            }
            $this->assertSame(
                $expectedOnSite,
                $onSiteBody,
                "[d] On-Site Contact body must render client contact or 'Not provided'. {$context}"
            );
        }
    }

    /**
     * Extract the body of a named, blank-line-delimited section ("Header:\n{body}").
     * Returns null when the section header is not present in the description.
     */
    private function sectionBody(string $description, string $header): ?string
    {
        foreach (explode("\n\n", $description) as $block) {
            if ($block === $header) {
                return '';
            }
            if (str_starts_with($block, $header . "\n")) {
                return substr($block, strlen($header) + 1);
            }
        }

        return null;
    }
}
