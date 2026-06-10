<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Message;
use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Messaging\ManualNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 22: Preview reports unresolved template variables
 *
 * Validates: Requirements 12.5, 12.8
 *
 * {@see ManualNotificationService::preview()} renders a manual notification without sending
 * (render-only — Req 12.5) and returns a `missing_variables` list naming every template
 * variable that did not resolve for the Shoot (Req 12.8). The universal invariant under test:
 *
 *   For any manual notification type, recipient, and any MessageTemplate whose required
 *   variable set (`variables_json`) is partitioned into a *resolvable* subset R (variables the
 *   resolver fills with a non-empty value for the previewed Shoot) and an *unresolvable* subset
 *   U (variables the resolver can never fill), preview()'s `missing_variables`:
 *
 *     (A) contains every variable in U                       (unresolved ⇒ reported), and
 *     (B) contains no variable in R                          (resolved ⇒ not reported), so
 *     (C) when U is empty (a fully-resolvable template) `missing_variables` is empty.
 *
 * The resolvable pool is drawn from variables the {@see \App\Services\Messaging\TemplateVariableResolver}
 * always fills non-empty for a Shoot whose recipient (client or photographer) has a name and
 * email: recipient_name / recipient_first_name / recipient_email / recipient_type, plus the
 * environment-derived current_date and portal_url. The unresolvable pool is gibberish keys
 * (`zz_<hex>`) the resolver never produces, so they remain absent from the resolved context.
 *
 * No PHP property-based-testing library is configured for the backend, so this test follows the
 * same "deterministic edge cases + seeded PRNG" approach used by the sibling property tests in
 * this spec ({@see ManualDispatchMappedTemplatePropertyTest},
 * {@see \Tests\Unit\Shoots\ShootEditingPayloadFilteringPropertyTest}): a fixed table of boundary
 * shapes (fully resolvable, all unresolvable, single unresolvable, empty variables_json, mixed)
 * plus a seeded generator producing 30 randomized (type, recipient, R-subset, U-subset) cases.
 * The same invariant must hold for every generated input.
 */
class PreviewMissingVariablesPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 20 randomized cases; we generate 30. */
    private const RANDOM_ITERATIONS = 30;

    private const RECIPIENT_TYPES = ['client', 'photographer'];

    /**
     * Variables the resolver fills with a non-empty value for any Shoot whose recipient has a
     * name + email. Every key here must NOT appear in missing_variables (sub-property B).
     *
     * @var list<string>
     */
    private const RESOLVABLE_POOL = [
        'recipient_name',
        'recipient_first_name',
        'recipient_email',
        'recipient_type',
        'current_date',
        'portal_url',
    ];

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = User::factory()->create(['role' => 'admin']);
    }

    /**
     * The main property: missing_variables contains exactly the unresolvable variables that are
     * required by the template, and never the resolvable ones.
     *
     * Validates: Requirements 12.5, 12.8
     */
    public function test_preview_reports_exactly_the_unresolved_required_variables(): void
    {
        foreach ($this->casesGenerator() as $i => [$type, $recipientType, $resolvable, $unresolvable]) {
            $context = sprintf(
                'iteration %d (type=%s, recipient=%s, R=[%s], U=[%s])',
                $i,
                $type,
                $recipientType,
                implode(',', $resolvable),
                implode(',', $unresolvable)
            );

            $shoot = $this->shootForCase();
            $this->seedTemplateForType($type, $resolvable, $unresolvable);

            $preview = app(ManualNotificationService::class)
                ->preview($shoot, $type, $recipientType);

            $missing = $preview['missing_variables'];
            $this->assertIsArray($missing, "missing_variables must be an array for {$context}");

            // (A) Every required-but-unresolvable variable is reported.
            foreach ($unresolvable as $key) {
                $this->assertContains(
                    $key,
                    $missing,
                    "[A] unresolved required variable '{$key}' must be reported for {$context}"
                );
            }

            // (B) No resolvable variable is reported as missing.
            foreach ($resolvable as $key) {
                $this->assertNotContains(
                    $key,
                    $missing,
                    "[B] resolved variable '{$key}' must not be reported as missing for {$context}"
                );
            }

            // (C) When nothing is unresolvable, the list is empty (fully-resolvable template).
            if ($unresolvable === []) {
                $this->assertSame(
                    [],
                    $missing,
                    "[C] a fully-resolvable template must yield an empty missing_variables for {$context}"
                );
            }

            // Render-only invariant (Req 12.5): preview never persists a Message or audit entry.
            $this->assertSame(0, Message::count(), "preview() must not dispatch a Message for {$context}");
            $this->assertSame(
                0,
                UserActivityLog::where('event_type', 'notification.manual_send')->count(),
                "preview() must not write an audit entry for {$context}"
            );
        }
    }

    /**
     * Focused direction of the property: a template whose required variables are all resolvable
     * yields an empty missing_variables list across every type and recipient.
     *
     * Validates: Requirements 12.5, 12.8
     */
    public function test_fully_resolvable_templates_report_no_missing_variables(): void
    {
        foreach (array_keys(ManualNotificationService::TYPES) as $type) {
            foreach (self::RECIPIENT_TYPES as $recipientType) {
                $context = "type={$type}, recipient={$recipientType}";

                $shoot = $this->shootForCase();
                $this->seedTemplateForType($type, self::RESOLVABLE_POOL, []);

                $preview = app(ManualNotificationService::class)
                    ->preview($shoot, $type, $recipientType);

                $this->assertSame(
                    [],
                    $preview['missing_variables'],
                    "fully-resolvable template must report no missing variables for {$context}"
                );
            }
        }
    }

    /**
     * Generator: deterministic edge cases + seeded randomized (type, recipient, R, U) tuples.
     *
     * Edge cases pin the boundary shapes of the partition the invariant ranges over; the seeded
     * randomized cases vary the type, recipient, and both subsets so the invariant is exercised
     * across the input space without depending on iteration order or hidden coupling.
     *
     * @return list<array{0:string,1:string,2:list<string>,3:list<string>}>
     *         list of [type, recipientType, resolvableSubset, unresolvableSubset]
     */
    private function casesGenerator(): array
    {
        $types = array_keys(ManualNotificationService::TYPES);

        // Deterministic boundary shapes.
        $cases = [
            // Fully resolvable — empty missing expected.
            ['shoot_scheduled', 'client', self::RESOLVABLE_POOL, []],
            // Single unresolvable, no resolvable required vars.
            ['shoot_scheduled', 'client', [], ['mystery_field']],
            // Mixed: one resolvable + one unresolvable, photographer recipient.
            ['shoot_cancelled', 'photographer', ['recipient_name'], ['mystery_field']],
            // All-unresolvable required set.
            ['payment_due', 'client', [], ['zz_alpha', 'zz_beta', 'zz_gamma']],
            // Mix with the full resolvable pool plus one unresolvable.
            ['shoot_ready', 'client', self::RESOLVABLE_POOL, ['unknown_token']],
            // Empty required set — nothing to report.
            ['payment_receipt', 'photographer', [], []],
            // On-hold type, mixed.
            ['shoot_on_hold', 'photographer', ['recipient_email', 'current_date'], ['zz_hold']],
        ];

        // Seeded PRNG so the generator is reproducible across runs.
        mt_srand(20260613);

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $type = $types[mt_rand(0, count($types) - 1)];
            $recipientType = self::RECIPIENT_TYPES[mt_rand(0, count(self::RECIPIENT_TYPES) - 1)];

            // Random resolvable subset (0..all) drawn from the resolvable pool.
            $resolvable = $this->randomSubset(self::RESOLVABLE_POOL);

            // 0..3 gibberish unresolvable keys that the resolver can never fill.
            $unresolvable = [];
            $uCount = mt_rand(0, 3);
            for ($u = 0; $u < $uCount; $u++) {
                $unresolvable[] = 'zz_' . bin2hex(random_bytes(4));
            }

            $cases[] = [$type, $recipientType, $resolvable, array_values(array_unique($unresolvable))];
        }

        return $cases;
    }

    /**
     * Return a random subset (preserving order) of the given pool.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function randomSubset(array $pool): array
    {
        $subset = [];
        foreach ($pool as $item) {
            if (mt_rand(0, 1) === 1) {
                $subset[] = $item;
            }
        }

        return $subset;
    }

    /**
     * Seed exactly one active MessageTemplate for the given manual notification type, whose
     * required variable set (`variables_json`) is the union of the resolvable and unresolvable
     * subsets and whose body references each via a {{placeholder}} so the renderer pipeline runs
     * end to end. Any pre-seeded row for the slug is removed first so resolveTemplate() grounds
     * in this single, test-owned template.
     *
     * @param  list<string>  $resolvable
     * @param  list<string>  $unresolvable
     */
    private function seedTemplateForType(string $type, array $resolvable, array $unresolvable): void
    {
        $slug = ManualNotificationService::TYPES[$type];

        MessageTemplate::where('slug', $slug)->delete();

        $required = array_values(array_unique(array_merge($resolvable, $unresolvable)));

        // Body references each required variable. Lead with "Details" (not Hi/Hello) so the
        // renderer's leading-greeting strip does not remove the placeholders.
        $placeholders = $required === []
            ? 'Details for your shoot.'
            : 'Details: ' . implode(' ', array_map(fn (string $k) => '{{' . $k . '}}', $required));

        MessageTemplate::create([
            'channel'        => 'EMAIL',
            'name'           => ucfirst(str_replace('-', ' ', $slug)),
            'slug'           => $slug,
            'description'    => null,
            'category'       => 'GENERAL',
            'subject'        => 'Subject for ' . $slug,
            'body_html'      => '<p>' . $placeholders . '</p>',
            'body_text'      => $placeholders,
            'variables_json' => $required,
            'scope'          => 'SYSTEM',
            'is_system'      => true,
            'is_active'      => true,
        ]);
    }

    /**
     * Build a Shoot whose client and photographer both carry a name + email so any recipient
     * choice resolves the recipient_* variables to non-empty values.
     */
    private function shootForCase(): Shoot
    {
        $client = User::factory()->create([
            'email' => 'client+' . uniqid('', true) . '@example.com',
            'name'  => 'Casey Client',
        ]);
        $photographer = User::factory()->create([
            'email' => 'photog+' . uniqid('', true) . '@example.com',
            'name'  => 'Pat Photographer',
            'role'  => 'photographer',
        ]);

        return Shoot::factory()->create([
            'client_id'       => $client->id,
            'photographer_id' => $photographer->id,
        ]);
    }
}
