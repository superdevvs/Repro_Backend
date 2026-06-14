<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Preservation tests + invariant baseline snapshot for QA issue #4
 * ("Shoot email completeness & cross-template consistency review").
 *
 * Property 2 (Preservation): for every template where the bug condition does
 * NOT hold, and for the STRUCTURAL invariants of every seeded template, the
 * fixed seeder SHALL preserve behaviour - the same slug, category, channel and
 * declared variables_json (3.4); the same valid token placeholders and their
 * mappings (3.3); the same set of seeded SYSTEM templates and automation rules
 * (3.5); and the same automation trigger -> template slug resolution, including
 * the SMS-vs-email branch for PROPERTY_CONTACT_REMINDER (3.6).
 *
 * Methodology: observation-first. The expected values below were OBSERVED from
 * the current (UNFIXED) seeder output and committed here as the baseline
 * snapshot. These tests PASS on the unfixed code (confirming the baseline to
 * preserve) and are re-run UNCHANGED after the fix (task 3.8) to prove the
 * central wrapper/snippet refactor did not silently change any structural
 * wiring.
 *
 * The central fix promotes getEmailWrapper() into a shared header/footer, so it
 * is allowed to ADD shared tokens (e.g. the canonical contact line) to a body;
 * what must NEVER happen is a previously-used token being DROPPED or a token
 * ceasing to resolve. Token preservation is therefore asserted as
 *   baseline_tokens(T) is a subset of seeded_tokens(T)
 * together with "every token resolves" (it is a mapped runtime variable or a
 * declared variable). slug/category/channel/variables_json and the template /
 * automation sets are asserted as EXACT equality.
 *
 * Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6
 */
class ShootEmailCompletenessPreservationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Number of randomized iterations used by the property-based style checks.
     * Each iteration reshuffles the template / token ordering to assert the
     * invariants are order-independent across the template/token domain.
     */
    private const PBT_ITERATIONS = 50;

    /**
     * Canonical runtime variables produced by MessagingSystemSeeder::$tokenMap
     * (the right-hand mustache values). Mirrored here so the test can assert a
     * body token "resolves through $tokenMap/transformContent()" without
     * touching the seeder's private state. A body token is considered valid if
     * it is one of these mapped variables OR a variable declared in the
     * template's own variables_json.
     */
    private const MAPPED_VARIABLES = [
        'greeting', 'client_first_name', 'client_last_name', 'client_company',
        'client_email', 'client_phone', 'company_name', 'company_email',
        'portal_url', 'password_reset_link', 'shoot_location', 'shoot_date',
        'shoot_time', 'shoot_packages', 'shoot_total', 'shoot_notes',
        'photographer_first_name', 'photographer_last_name', 'photographer_name',
        'payment_link', 'shoot_completed_date', 'current_date', 'payment_amount',
        'small_zip_link', 'full_zip_link', 'mls_tour_link', 'branded_tour_link',
        'shoot_change_summary', 'shoot_changes_html', 'decline_reason',
        'photo_count', 'download_link', 'invoice_number', 'amount_due',
        'due_date', 'payment_date', 'services_provided', 'assigned_photographers',
        'cancellation_reason', 'refund_amount', 'original_invoice', 'refund_date',
        'refund_reason', 'shoot_duration', 'shoot_address', 'email_signature',
        'custom_scheduling_fields', 'misc_link_title', 'misc_link_url',
        'services_provided_html', 'recipient_booking_intro', 'recipient_update_intro',
        'recipient_manage_copy', 'recipient_manage_copy_text', 'payment_cta_html',
        'payment_cta_text', 'property_prep_html', 'property_prep_text',
        'cancellation_policy_html', 'cancellation_policy_text',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the SYSTEM templates + automation rules into the test DB and
        // assert against the seeded (normalized/transformed) content, which is
        // the authoritative artifact under review.
        app(MessagingSystemSeeder::class)->run();
    }

    /**
     * Invariant baseline snapshot OBSERVED from the UNFIXED seeder.
     *
     * slug => [category, channel, variables_json (post mapVariables), body_tokens
     * (the set of {{mustache}} variables present across body_html + body_text)].
     *
     * @return array<string, array{category:string, channel:string, variables_json:list<string>, body_tokens:list<string>}>
     */
    private function baselineTemplates(): array
    {
        return [
            'account-created' => [
                'category' => 'ACCOUNT', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'client_last_name', 'client_company', 'client_email', 'client_phone', 'company_name', 'company_email', 'portal_url', 'password_reset_link'],
                'body_tokens' => ['client_company', 'client_email', 'client_first_name', 'client_last_name', 'client_phone', 'company_email', 'greeting', 'password_reset_link', 'portal_url'],
            ],
            'shoot-scheduled' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_total', 'shoot_notes', 'company_email', 'portal_url', 'recipient_booking_intro', 'recipient_manage_copy', 'payment_cta_html', 'property_prep_html', 'cancellation_policy_html'],
                'body_tokens' => ['assigned_photographers', 'cancellation_policy_html', 'cancellation_policy_text', 'company_email', 'greeting', 'payment_cta_html', 'payment_cta_text', 'property_prep_html', 'property_prep_text', 'recipient_booking_intro', 'recipient_manage_copy', 'recipient_manage_copy_text', 'services_provided', 'services_provided_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time', 'shoot_total'],
            ],
            'shoot-requested' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'shoot_date', 'shoot_time', 'services_provided', 'services_provided_html', 'shoot_total', 'shoot_notes', 'company_email', 'portal_url'],
                'body_tokens' => ['client_first_name', 'company_email', 'greeting', 'portal_url', 'services_provided', 'services_provided_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time', 'shoot_total'],
            ],
            'shoot-request-approved' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first_name', 'photographer_last_name', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_total', 'shoot_notes', 'payment_link', 'company_email', 'portal_url', 'shoot_change_summary', 'shoot_changes_html'],
                'body_tokens' => ['client_first_name', 'company_email', 'greeting', 'payment_link', 'photographer_first_name', 'photographer_last_name', 'portal_url', 'services_provided', 'services_provided_html', 'shoot_change_summary', 'shoot_changes_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time', 'shoot_total'],
            ],
            'shoot-request-modified' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first_name', 'photographer_last_name', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_total', 'shoot_notes', 'payment_link', 'company_email', 'portal_url', 'shoot_change_summary', 'shoot_changes_html'],
                'body_tokens' => ['client_first_name', 'company_email', 'greeting', 'photographer_first_name', 'photographer_last_name', 'portal_url', 'services_provided', 'services_provided_html', 'shoot_change_summary', 'shoot_changes_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time', 'shoot_total'],
            ],
            'shoot-request-declined' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'decline_reason', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first_name', 'photographer_last_name', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_notes', 'company_email'],
                'body_tokens' => ['client_first_name', 'company_email', 'decline_reason', 'greeting', 'photographer_first_name', 'photographer_last_name', 'services_provided', 'services_provided_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time'],
            ],
            'shoot-reminder' => [
                'category' => 'REMINDER', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first_name', 'photographer_last_name', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_notes', 'company_email'],
                'body_tokens' => ['client_first_name', 'company_email', 'greeting', 'photographer_first_name', 'photographer_last_name', 'services_provided', 'services_provided_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time'],
            ],
            'shoot-updated' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_notes', 'company_email', 'portal_url', 'recipient_update_intro', 'recipient_manage_copy', 'shoot_change_summary', 'shoot_changes_html', 'property_prep_html', 'cancellation_policy_html'],
                'body_tokens' => ['assigned_photographers', 'cancellation_policy_html', 'cancellation_policy_text', 'company_email', 'greeting', 'property_prep_html', 'property_prep_text', 'recipient_manage_copy', 'recipient_manage_copy_text', 'recipient_update_intro', 'services_provided', 'services_provided_html', 'shoot_change_summary', 'shoot_changes_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time'],
            ],
            'shoot-ready' => [
                'category' => 'GENERAL', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first_name', 'photographer_last_name', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_total', 'shoot_notes', 'payment_link', 'portal_url', 'company_email'],
                'body_tokens' => ['client_first_name', 'company_email', 'greeting', 'payment_link', 'photographer_first_name', 'photographer_last_name', 'portal_url', 'services_provided', 'services_provided_html', 'shoot_location', 'shoot_notes', 'shoot_total'],
            ],
            'photographer-assigned' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'services_provided', 'services_provided_html', 'shoot_notes', 'portal_url', 'company_email'],
                'body_tokens' => ['company_email', 'greeting', 'portal_url', 'services_provided', 'services_provided_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time'],
            ],
            'photographer-changed' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'services_provided', 'services_provided_html', 'shoot_notes', 'portal_url', 'company_email', 'previous_photographer_name', 'new_photographer_name', 'shoot_change_summary'],
                'body_tokens' => ['company_email', 'greeting', 'portal_url', 'services_provided', 'services_provided_html', 'shoot_date', 'shoot_location', 'shoot_notes', 'shoot_time'],
            ],
            'property-contact-reminder' => [
                'category' => 'REMINDER', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'shoot_date', 'shoot_time', 'portal_url', 'company_email'],
                'body_tokens' => ['client_first_name', 'company_email', 'greeting', 'portal_url', 'shoot_date', 'shoot_location', 'shoot_time'],
            ],
            'property-contact-reminder-sms' => [
                'category' => 'REMINDER', 'channel' => 'SMS',
                'variables_json' => ['shoot_location', 'shoot_date', 'shoot_time', 'portal_url'],
                'body_tokens' => ['portal_url', 'shoot_date', 'shoot_location', 'shoot_time'],
            ],
            'shoot-delivered' => [
                'category' => 'GENERAL', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'services_provided', 'services_provided_html', 'assigned_photographers', 'small_zip_link', 'full_zip_link', 'mls_tour_link', 'branded_tour_link', 'portal_url', 'company_email'],
                'body_tokens' => ['branded_tour_link', 'company_email', 'full_zip_link', 'greeting', 'mls_tour_link', 'portal_url', 'services_provided', 'services_provided_html', 'shoot_date', 'shoot_location', 'shoot_time', 'small_zip_link'],
            ],
            'shoot-summary' => [
                'category' => 'GENERAL', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'services_provided', 'services_provided_html', 'assigned_photographers', 'small_zip_link', 'full_zip_link', 'mls_tour_link', 'branded_tour_link', 'portal_url', 'company_email'],
                'body_tokens' => ['branded_tour_link', 'client_first_name', 'company_email', 'full_zip_link', 'greeting', 'mls_tour_link', 'portal_url', 'services_provided', 'services_provided_html', 'shoot_location', 'small_zip_link'],
            ],
            'payment-due-reminder' => [
                'category' => 'INVOICE', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'client_name', 'company_email', 'invoice_number', 'amount_due', 'due_date', 'payment_link'],
                'body_tokens' => ['amount_due', 'company_email', 'due_date', 'greeting', 'invoice_number', 'payment_link'],
            ],
            'payment-thank-you' => [
                'category' => 'PAYMENT', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'client_last_name', 'shoot_location', 'current_date', 'payment_amount', 'services_provided', 'services_provided_html', 'assigned_photographers', 'shoot_notes', 'company_email'],
                'body_tokens' => ['client_first_name', 'client_last_name', 'company_email', 'current_date', 'greeting', 'payment_amount', 'services_provided', 'services_provided_html', 'shoot_location', 'shoot_notes'],
            ],
            'refund-submitted' => [
                'category' => 'PAYMENT', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'services_provided', 'services_provided_html', 'assigned_photographers', 'shoot_notes'],
                'body_tokens' => ['services_provided', 'services_provided_html', 'shoot_location', 'shoot_notes'],
            ],
            'shoot-deleted' => [
                'category' => 'BOOKING', 'channel' => 'EMAIL',
                'variables_json' => ['greeting', 'client_first_name', 'shoot_location', 'services_provided', 'services_provided_html', 'assigned_photographers', 'shoot_notes', 'company_email'],
                'body_tokens' => ['client_first_name', 'company_email', 'greeting', 'services_provided', 'services_provided_html', 'shoot_location', 'shoot_notes'],
            ],
            'weekly-invoice-generated' => [
                'category' => 'INVOICE', 'channel' => 'EMAIL',
                'variables_json' => ['recipient_name', 'recipient_role', 'billing_period', 'invoice_number', 'invoice_status', 'invoice_total', 'invoice_items_html', 'invoice_items_text', 'dashboard_url', 'invoice_next_step', 'approval_note'],
                'body_tokens' => ['approval_note', 'billing_period', 'dashboard_url', 'invoice_items_html', 'invoice_items_text', 'invoice_next_step', 'invoice_number', 'invoice_status', 'invoice_total', 'recipient_name', 'recipient_role'],
            ],
        ];
    }

    /**
     * Invariant baseline snapshot of every SYSTEM automation rule OBSERVED from
     * the UNFIXED seeder: "trigger_type|name" => resolved template slug.
     * The compound key is required because PROPERTY_CONTACT_REMINDER resolves to
     * TWO different templates depending on the SMS-vs-email branch.
     *
     * @return array<string, string|null>
     */
    private function baselineAutomationResolution(): array
    {
        return [
            'ACCOUNT_CREATED|Send Account Creation Email' => 'account-created',
            'INVOICE_DUE|Invoice Due Reminder' => 'payment-due-reminder',
            'INVOICE_OVERDUE|Invoice Overdue Reminder' => 'payment-due-reminder',
            'PAYMENT_COMPLETED|Payment Confirmation' => 'payment-thank-you',
            'PAYMENT_REFUNDED|Refund Notification' => 'refund-submitted',
            'PHOTOGRAPHER_ASSIGNED|Photographer Assignment Notification' => 'photographer-assigned',
            'PHOTOGRAPHER_CHANGED|Photographer Change Notification' => 'photographer-changed',
            'PROPERTY_CONTACT_REMINDER|Property Contact Reminder - 1 Day Before' => 'property-contact-reminder',
            'PROPERTY_CONTACT_REMINDER|Property Contact Reminder - 2 Days Before' => 'property-contact-reminder',
            'PROPERTY_CONTACT_REMINDER|Property Contact Reminder - Shoot Day' => 'property-contact-reminder',
            'PROPERTY_CONTACT_REMINDER|Property Contact Reminder SMS - 1 Day Before' => 'property-contact-reminder-sms',
            'PROPERTY_CONTACT_REMINDER|Property Contact Reminder SMS - 2 Days Before' => 'property-contact-reminder-sms',
            'PROPERTY_CONTACT_REMINDER|Property Contact Reminder SMS - Shoot Day' => 'property-contact-reminder-sms',
            'SHOOT_BOOKED|Shoot Booking Confirmation' => 'shoot-scheduled',
            'SHOOT_CANCELED|Shoot Cancelled Notification' => 'shoot-deleted',
            'SHOOT_COMPLETED|Photos Ready Notification' => 'shoot-ready',
            'SHOOT_REMINDER|Shoot Reminder' => 'shoot-reminder',
            'SHOOT_REMOVED|Shoot Removed Notification' => 'shoot-deleted',
            'SHOOT_REQUESTED|Shoot Request Received' => 'shoot-requested',
            'SHOOT_REQUEST_APPROVED|Shoot Request Approved' => 'shoot-request-approved',
            'SHOOT_REQUEST_DECLINED|Shoot Request Declined' => 'shoot-request-declined',
            'SHOOT_REQUEST_MODIFIED|Shoot Request Modified' => 'shoot-request-modified',
            'SHOOT_UPDATED|Shoot Updated Notification' => 'shoot-updated',
        ];
    }

    /** Baseline (slug => null removed) cleaned of any placeholder entries. */
    private function baseline(): array
    {
        return array_filter(
            $this->baselineTemplates(),
            fn ($v) => $v !== null
        );
    }

    /** Extract the set of {{mustache}} variables present in a string. */
    private function extractTokens(string $content): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $content, $m);
        $tokens = array_values(array_unique($m[1]));
        sort($tokens);

        return $tokens;
    }

    private function seededTokensFor(MessageTemplate $t): array
    {
        return $this->extractTokens((string) $t->body_html . "\n" . (string) $t->body_text);
    }

    // ---------------------------------------------------------------------
    // 3.5 - The same SET of seeded SYSTEM templates (20) is produced.
    // ---------------------------------------------------------------------
    public function test_seeded_system_template_set_matches_baseline(): void
    {
        $expectedSlugs = array_keys($this->baseline());
        sort($expectedSlugs);

        $this->assertCount(20, $expectedSlugs, 'Baseline must describe exactly 20 SYSTEM templates.');

        $actualSlugs = MessageTemplate::query()
            ->where('is_system', true)
            ->pluck('slug')
            ->all();
        sort($actualSlugs);

        $this->assertSame(
            $expectedSlugs,
            $actualSlugs,
            'The seeded SYSTEM template set must match the baseline 20 templates (3.5).'
        );
    }

    // ---------------------------------------------------------------------
    // 3.4 - slug, category, channel and post-mapVariables() variables_json are
    // preserved EXACTLY for every seeded template.
    // ---------------------------------------------------------------------
    public function test_slug_category_channel_and_variables_json_match_baseline(): void
    {
        foreach ($this->baseline() as $slug => $expected) {
            $template = MessageTemplate::query()->where('slug', $slug)->first();
            $this->assertNotNull($template, "Expected SYSTEM template '{$slug}' to be seeded.");

            // slug is the lookup key, so its preservation is implied; assert it
            // explicitly to lock the invariant.
            $this->assertSame($slug, $template->slug, "slug for '{$slug}' changed.");

            $this->assertSame(
                $expected['category'],
                $template->category,
                "category for '{$slug}' must match the baseline (3.4)."
            );

            $this->assertSame(
                $expected['channel'],
                $template->channel,
                "channel for '{$slug}' must match the baseline (3.4)."
            );

            $this->assertSame(
                $expected['variables_json'],
                $template->variables_json,
                "variables_json for '{$slug}' must match the baseline post-mapVariables() declaration (3.4)."
            );
        }
    }

    // ---------------------------------------------------------------------
    // 3.3 - Valid token placeholders per body are preserved and each resolves.
    // Preservation semantics: baseline tokens are a subset of the seeded tokens
    // (none dropped), and every seeded token resolves (mapped runtime variable
    // or a declared variable).
    // ---------------------------------------------------------------------
    public function test_body_tokens_are_preserved_and_resolvable(): void
    {
        foreach ($this->baseline() as $slug => $expected) {
            $template = MessageTemplate::query()->where('slug', $slug)->firstOrFail();
            $seededTokens = $this->seededTokensFor($template);

            // (a) No previously-used token may be dropped.
            $dropped = array_values(array_diff($expected['body_tokens'], $seededTokens));
            $this->assertSame(
                [],
                $dropped,
                "Template '{$slug}' dropped previously-valid token(s): " . implode(', ', $dropped) . ' (3.3).'
            );

            // (b) Every seeded token must resolve: it is a mapped runtime
            // variable (from $tokenMap) or a declared variable for this template.
            $resolvable = array_merge(self::MAPPED_VARIABLES, $template->variables_json ?? []);
            $unresolved = array_values(array_diff($seededTokens, $resolvable));
            $this->assertSame(
                [],
                $unresolved,
                "Template '{$slug}' uses token(s) that do not resolve through \$tokenMap/variables_json: "
                . implode(', ', $unresolved) . ' (3.3).'
            );
        }
    }

    // ---------------------------------------------------------------------
    // 3.5 - The same SET of SYSTEM automation rules is produced.
    // ---------------------------------------------------------------------
    public function test_seeded_automation_rule_set_matches_baseline(): void
    {
        $expectedKeys = array_keys($this->baselineAutomationResolution());
        sort($expectedKeys);

        $this->assertCount(23, $expectedKeys, 'Baseline must describe exactly 23 SYSTEM automation rules.');

        $actualKeys = AutomationRule::query()
            ->where('scope', 'SYSTEM')
            ->get()
            ->map(fn (AutomationRule $r) => $r->trigger_type . '|' . $r->name)
            ->all();
        sort($actualKeys);

        $this->assertSame(
            $expectedKeys,
            $actualKeys,
            'The seeded SYSTEM automation rule set (trigger_type|name) must match the baseline (3.5).'
        );
    }

    // ---------------------------------------------------------------------
    // 3.6 - Each automation trigger_type -> template slug resolution is
    // preserved, including the SMS-vs-email branch for PROPERTY_CONTACT_REMINDER.
    // ---------------------------------------------------------------------
    public function test_automation_trigger_to_template_resolution_matches_baseline(): void
    {
        $expected = $this->baselineAutomationResolution();

        $slugById = MessageTemplate::query()->pluck('slug', 'id')->all();

        $actual = [];
        foreach (AutomationRule::query()->where('scope', 'SYSTEM')->get() as $rule) {
            $key = $rule->trigger_type . '|' . $rule->name;
            $actual[$key] = $rule->template_id !== null
                ? ($slugById[$rule->template_id] ?? null)
                : null;
        }

        ksort($expected);
        ksort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'Every automation trigger_type|name must resolve to the same template slug as the baseline (3.6).'
        );
    }

    // ---------------------------------------------------------------------
    // 3.6 (focused) - The PROPERTY_CONTACT_REMINDER SMS-vs-email branch.
    // ---------------------------------------------------------------------
    public function test_property_contact_reminder_sms_vs_email_branch_is_preserved(): void
    {
        $slugById = MessageTemplate::query()->pluck('slug', 'id')->all();

        $rules = AutomationRule::query()
            ->where('scope', 'SYSTEM')
            ->where('trigger_type', 'PROPERTY_CONTACT_REMINDER')
            ->get();

        $this->assertCount(6, $rules, 'There must be 6 PROPERTY_CONTACT_REMINDER rules (3 email + 3 SMS).');

        foreach ($rules as $rule) {
            $slug = $rule->template_id !== null ? ($slugById[$rule->template_id] ?? null) : null;
            $expectedSlug = str_contains($rule->name, 'SMS')
                ? 'property-contact-reminder-sms'
                : 'property-contact-reminder';

            $this->assertSame(
                $expectedSlug,
                $slug,
                "PROPERTY_CONTACT_REMINDER rule '{$rule->name}' must resolve to '{$expectedSlug}' (3.6)."
            );
        }
    }

    // ---------------------------------------------------------------------
    // Property-based style: the structural invariants are order-independent.
    // Reshuffle template + token orderings across many iterations and assert
    // category/channel/variables_json equality and token preservation hold
    // regardless of ordering (covers the template/token domain, 3.3/3.4).
    // ---------------------------------------------------------------------
    public function test_structural_invariants_hold_under_randomized_orderings(): void
    {
        $baseline = $this->baseline();

        for ($i = 0; $i < self::PBT_ITERATIONS; $i++) {
            mt_srand($i);

            $slugs = array_keys($baseline);
            shuffle($slugs);

            foreach ($slugs as $slug) {
                $expected = $baseline[$slug];
                $template = MessageTemplate::query()->where('slug', $slug)->firstOrFail();

                $this->assertSame($expected['category'], $template->category, "iter {$i}: category drift for {$slug}");
                $this->assertSame($expected['channel'], $template->channel, "iter {$i}: channel drift for {$slug}");

                // variables_json order is part of the declared contract; assert
                // exact equality regardless of the shuffled visit order.
                $this->assertSame($expected['variables_json'], $template->variables_json, "iter {$i}: variables_json drift for {$slug}");

                // Token preservation under a shuffled expected-token ordering.
                $expectedTokens = $expected['body_tokens'];
                shuffle($expectedTokens);
                $seededTokens = $this->seededTokensFor($template);
                foreach ($expectedTokens as $token) {
                    $this->assertContains(
                        $token,
                        $seededTokens,
                        "iter {$i}: token '{$token}' missing from seeded body of {$slug}"
                    );
                }
            }
        }
    }
}
