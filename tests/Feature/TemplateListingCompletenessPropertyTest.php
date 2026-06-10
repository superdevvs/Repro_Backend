<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 6: Template listing is complete and labeled
 *
 * Validates: Requirements 11.1, 11.2
 *
 * For any set of MessageTemplate records (mix of EMAIL and SMS), the messaging
 * template index endpoint returns exactly all of them and each row exposes
 * the template's identifying name and message type (channel).
 *
 * Three universal sub-properties are asserted for arbitrary fixture sets:
 *
 *   (A) Completeness (Req 11.1) — the set of templates returned across the
 *       channel-scoped index calls (EMAIL union SMS) equals exactly the set
 *       of seeded MessageTemplate records, with no missing or extra rows.
 *
 *   (B) Labeling (Req 11.2) — every returned record carries an identifying
 *       `name` (non-empty string) and a `channel` value drawn from the
 *       supported message-type set {EMAIL, SMS}.
 *
 *   (C) Channel filter correctness (supports Req 11.2 — message type) —
 *       querying the index with a specific channel filter returns exactly
 *       the subset of seeded templates whose channel matches and no others.
 *
 * The MessageTemplateController index defaults to `channel=EMAIL` when no
 * channel query parameter is supplied, so "all templates" is asserted by
 * issuing channel=EMAIL and channel=SMS reads and combining the result. That
 * mirrors how AC 11.1's "list of all templates including email and text"
 * is observed through the existing endpoint.
 *
 * Because no PHP property-based-testing library is installed in this
 * project, the test follows the spec's "strong randomization plus
 * deterministic edge cases" approach: 20 randomized fixture sets (varying
 * total counts and EMAIL/SMS ratios) plus 4 deterministic edge cases
 * (empty, all-email, all-sms, mixed). The same universal property must
 * hold for every generated input.
 */
class TemplateListingCompletenessPropertyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Spec mandates >= 20 randomized cases. We run 20 plus 4 deterministic
     * edge cases for full coverage of the input space.
     */
    private const RANDOM_ITERATIONS = 20;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * Generator: 20 random + 4 deterministic edge fixture sets.
     *
     * Each entry is a list<array{channel:'EMAIL'|'SMS',name:string,category:?string}>
     * describing the templates to seed for one iteration. Random sets vary
     * the total count (0..15) and the EMAIL/SMS ratio so the property is
     * exercised across small, medium, and unbalanced collections.
     *
     * Edge cases force coverage of three structural extremes plus a
     * balanced-mix baseline:
     *   - Empty: 0 templates (degenerate completeness — both filters return [])
     *   - All EMAIL: 5 EMAIL, 0 SMS (SMS filter must return [])
     *   - All SMS: 5 SMS, 0 EMAIL (EMAIL filter must return [])
     *   - Mixed: 3 EMAIL + 3 SMS (each filter returns its own subset)
     *
     * @return list<list<array{channel:string,name:string,category:?string}>>
     */
    private function fixtureGenerator(): array
    {
        $categories = ['BOOKING', 'REMINDER', 'PAYMENT', 'INVOICE', 'ACCOUNT', 'GENERAL', null];

        $sets = [];

        // 20 randomized fixture sets — total count in [0..15], ratio uniformly random.
        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $count = mt_rand(0, 15);
            $set = [];
            for ($j = 0; $j < $count; $j++) {
                $channel = mt_rand(0, 1) === 0 ? 'EMAIL' : 'SMS';
                $set[] = [
                    'channel'  => $channel,
                    // Names must be unique across the fixture (DB has no unique
                    // constraint, but unique names give us a stable identifier
                    // when comparing the response set against the fixture set).
                    'name'     => sprintf('rand_iter%02d_idx%02d_%s', $i, $j, strtolower($channel)),
                    'category' => $categories[array_rand($categories)],
                ];
            }
            $sets[] = $set;
        }

        // Deterministic edge cases.

        // Empty fixture — both filters must return [].
        $sets[] = [];

        // All EMAIL — SMS filter must return []; EMAIL filter must return all 5.
        $sets[] = array_map(
            fn (int $j) => [
                'channel' => 'EMAIL',
                'name' => sprintf('edge_all_email_%02d', $j),
                'category' => 'GENERAL',
            ],
            range(0, 4)
        );

        // All SMS — EMAIL filter must return []; SMS filter must return all 5.
        $sets[] = array_map(
            fn (int $j) => [
                'channel' => 'SMS',
                'name' => sprintf('edge_all_sms_%02d', $j),
                'category' => 'REMINDER',
            ],
            range(0, 4)
        );

        // Mixed (3 EMAIL + 3 SMS) — balanced baseline; categories drawn from
        // the full set so labeling is exercised across a realistic mix.
        $mixed = [];
        foreach (range(0, 2) as $j) {
            $mixed[] = ['channel' => 'EMAIL', 'name' => "edge_mix_email_$j", 'category' => 'BOOKING'];
            $mixed[] = ['channel' => 'SMS',   'name' => "edge_mix_sms_$j",   'category' => 'PAYMENT'];
        }
        $sets[] = $mixed;

        return $sets;
    }

    /**
     * Seed a MessageTemplate fixture set into the database.
     *
     * Truncates the message_templates table first so each iteration starts
     * from a known, empty baseline. Returns the ids of the persisted rows.
     *
     * @param  list<array{channel:string,name:string,category:?string}>  $fixture
     * @return list<int>
     */
    private function seedFixture(array $fixture): array
    {
        // Wipe any rows from a previous iteration. RefreshDatabase only runs
        // migrations once per test method, so the table accumulates state
        // across iterations of this single test.
        DB::table('message_templates')->delete();

        $ids = [];
        foreach ($fixture as $row) {
            $template = MessageTemplate::create([
                'channel'     => $row['channel'],
                'name'        => $row['name'],
                'slug'        => str_replace('_', '-', $row['name']),
                'description' => null,
                'category'    => $row['category'],
                'subject'     => 'Subject for ' . $row['name'],
                'body_html'   => '<p>Body for ' . $row['name'] . '</p>',
                'body_text'   => 'Body for ' . $row['name'],
                'scope'       => 'SYSTEM',
                'is_system'   => true,
                'is_active'   => true,
                'created_by'  => $this->admin->id,
                'updated_by'  => $this->admin->id,
            ]);
            $ids[] = $template->id;
        }
        return $ids;
    }

    /**
     * The property: for any seeded set of MessageTemplate records, the
     * messaging template index returns exactly all of them across the
     * EMAIL/SMS channel reads, each row carries an identifying name and
     * channel, and channel filtering returns exactly the matching subset.
     *
     * Validates: Requirements 11.1, 11.2
     */
    public function test_template_listing_is_complete_and_labeled_for_arbitrary_fixtures(): void
    {
        foreach ($this->fixtureGenerator() as $i => $fixture) {
            $expectedIds = $this->seedFixture($fixture);

            $expectedEmailIds = collect($fixture)
                ->zip($expectedIds)
                ->filter(fn ($pair) => $pair[0]['channel'] === 'EMAIL')
                ->map(fn ($pair) => $pair[1])
                ->values()
                ->all();

            $expectedSmsIds = collect($fixture)
                ->zip($expectedIds)
                ->filter(fn ($pair) => $pair[0]['channel'] === 'SMS')
                ->map(fn ($pair) => $pair[1])
                ->values()
                ->all();

            $context = sprintf(
                'iteration %d (total=%d, email=%d, sms=%d)',
                $i,
                count($fixture),
                count($expectedEmailIds),
                count($expectedSmsIds)
            );

            // ----------------------------------------------------------------
            // Issue both channel-scoped reads as an authenticated admin (the
            // route group requires role:superadmin,admin).
            // ----------------------------------------------------------------
            $emailResponse = $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/messaging/templates?channel=EMAIL');

            $emailResponse->assertOk();
            $emailRows = $emailResponse->json();
            $this->assertIsArray($emailRows, "[setup] EMAIL listing must be a JSON array for {$context}");

            $smsResponse = $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/messaging/templates?channel=SMS');

            $smsResponse->assertOk();
            $smsRows = $smsResponse->json();
            $this->assertIsArray($smsRows, "[setup] SMS listing must be a JSON array for {$context}");

            // ----------------------------------------------------------------
            // (C) Channel filter correctness — each scoped read returns
            //     exactly the seeded subset for that channel.
            // ----------------------------------------------------------------
            $returnedEmailIds = array_map(fn ($r) => $r['id'], $emailRows);
            $returnedSmsIds   = array_map(fn ($r) => $r['id'], $smsRows);

            sort($returnedEmailIds);
            sort($returnedSmsIds);
            $expectedEmailSorted = $expectedEmailIds;
            sort($expectedEmailSorted);
            $expectedSmsSorted = $expectedSmsIds;
            sort($expectedSmsSorted);

            $this->assertSame(
                $expectedEmailSorted,
                $returnedEmailIds,
                "[C] channel=EMAIL must return exactly the seeded EMAIL templates for {$context}"
            );
            $this->assertSame(
                $expectedSmsSorted,
                $returnedSmsIds,
                "[C] channel=SMS must return exactly the seeded SMS templates for {$context}"
            );

            // Every row in the EMAIL response carries channel=EMAIL; same for SMS.
            foreach ($emailRows as $row) {
                $this->assertSame(
                    'EMAIL',
                    $row['channel'] ?? null,
                    "[C] every channel=EMAIL row must have channel=EMAIL for {$context}"
                );
            }
            foreach ($smsRows as $row) {
                $this->assertSame(
                    'SMS',
                    $row['channel'] ?? null,
                    "[C] every channel=SMS row must have channel=SMS for {$context}"
                );
            }

            // ----------------------------------------------------------------
            // (A) Completeness — the union of EMAIL + SMS reads equals the
            //     full seeded set (no missing rows, no spurious rows).
            // ----------------------------------------------------------------
            $unionIds = array_values(array_unique(array_merge($returnedEmailIds, $returnedSmsIds)));
            sort($unionIds);
            $expectedAllIdsSorted = $expectedIds;
            sort($expectedAllIdsSorted);

            $this->assertSame(
                $expectedAllIdsSorted,
                $unionIds,
                "[A] EMAIL ∪ SMS listing must equal the full seeded template set for {$context}"
            );

            $this->assertCount(
                count($fixture),
                $unionIds,
                "[A] union row count must match seeded count for {$context}"
            );

            // No row appears in both EMAIL and SMS responses (a template
            // belongs to exactly one channel — labeling stays unambiguous).
            $this->assertEmpty(
                array_intersect($returnedEmailIds, $returnedSmsIds),
                "[A] no template id may appear under both EMAIL and SMS for {$context}"
            );

            // ----------------------------------------------------------------
            // (B) Labeling — every returned row carries an identifying name
            //     (non-empty string) and a channel value drawn from the
            //     supported message-type set {EMAIL, SMS}. This is the
            //     observable surface the Dashboard renders ("name + type"
            //     per AC 11.2).
            // ----------------------------------------------------------------
            foreach (array_merge($emailRows, $smsRows) as $row) {
                $this->assertArrayHasKey('name', $row, "[B] row must include name for {$context}");
                $this->assertIsString($row['name'], "[B] row name must be a string for {$context}");
                $this->assertNotSame('', $row['name'], "[B] row name must be non-empty for {$context}");

                $this->assertArrayHasKey('channel', $row, "[B] row must include channel for {$context}");
                $this->assertContains(
                    $row['channel'],
                    ['EMAIL', 'SMS'],
                    "[B] row channel must be EMAIL or SMS for {$context}"
                );
            }
        }
    }
}
