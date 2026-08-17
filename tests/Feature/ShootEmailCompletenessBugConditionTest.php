<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug-condition exploration tests for QA issue #4
 * ("Shoot email completeness & cross-template consistency review").
 *
 * Property 1 (Bug Condition): for every in-set shoot template T where
 * isBugCondition(T) holds, the seeded content must be complete for its
 * lifecycle stage and consistent with the rest of the set (canonical brand,
 * canonical contact details, no placeholder links, consistent shared content,
 * consistent sign-off, and HTML/text agreement).
 *
 * These tests encode the expected fixed behavior: seeded bodies stay focused
 * on template content while shared email chrome is owned by the renderer.
 *
 * The property is scoped to the concrete in-set templates named in the design
 * (it iterates over the deterministic isBugCondition(T) candidates rather than
 * random strings, since the bug is deterministic per known template).
 *
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9
 */
class ShootEmailCompletenessBugConditionTest extends TestCase
{
    use RefreshDatabase;

    /** Canonical brand constants (mirror MessagingSystemSeeder::BRAND_*). */
    private const BRAND_NAME = 'R/E Pro Photos';
    private const BRAND_PHONE = '(202) 868-1663';
    private const BRAND_SITE = 'https://reprophotos.com';
    private const NON_CANONICAL_BRAND = 'R/E Pro Dashboard';

    /**
     * Canonical token (post-transformContent() form) for the brand email in the
     * shared footer contact line. The generators emit [company_email]; the
     * seeded body_html/body_text store the transformed {{company_email}} token.
     */
    private const COMPANY_EMAIL_TOKEN = '{{company_email}}';

    /** Canonical sign-off opener emitted by the shared footer (getSignOff*()). */
    private const SIGN_OFF_OPENER = 'Thanks,';

    /**
     * Canonical wording for the shared cancellation-policy and property-prep
     * copy (mirrors the single-source-of-truth snippet providers in the
     * seeder). Wherever this copy is inlined (rather than left as a token that
     * resolves to the same wording at runtime) it MUST read identically.
     */
    private const CANONICAL_CANCELLATION_SENTENCE =
        'a $60 cancellation fee may apply. Please cancel or reschedule at least 6 hours before the appointment start time whenever possible.';
    private const CANONICAL_PREP_SENTENCE =
        'make sure the property is ready before the scheduled time.';

    /**
     * Pre-fix divergent phrasings for the same shared concepts. After the fix
     * these non-canonical variants must not appear anywhere in the set.
     */
    private const NON_CANONICAL_CANCELLATION = 'cancellation fee of $60';
    private const NON_CANONICAL_PREP = 'please have the property ready';

    /**
     * The shoot communication set under review (recipient-journey order).
     * EMAIL templates only (the SMS template is handled separately).
     */
    private const IN_SET_EMAIL_SLUGS = [
        'account-created',
        'shoot-requested',
        'shoot-request-approved',
        'shoot-request-modified',
        'shoot-request-declined',
        'shoot-scheduled',
        'shoot-updated',
        'shoot-reminder',
        'photographer-assigned',
        'photographer-changed',
        'property-contact-reminder',
        'shoot-ready',
        'shoot-delivered',
        'shoot-summary',
        'payment-due-reminder',
        'payment-thank-you',
        'refund-submitted',
        'shoot-deleted',
        'weekly-invoice-generated',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the SYSTEM templates into the test DB. We assert against the
        // seeded content (output of the generator methods after
        // normalizeTemplateDefinition()/transformContent()), which is the
        // authoritative artifact under review.
        app(MessagingSystemSeeder::class)->run();
    }

    /**
     * @return array<string, MessageTemplate> keyed by slug
     */
    private function inSetEmailTemplates(): array
    {
        $templates = [];

        foreach (self::IN_SET_EMAIL_SLUGS as $slug) {
            $template = MessageTemplate::query()->where('slug', $slug)->first();
            $this->assertNotNull($template, "Expected in-set template '{$slug}' to be seeded.");
            $templates[$slug] = $template;
        }

        return $templates;
    }

    // ---------------------------------------------------------------------
    // Check 1: Brand-name (usesNonCanonicalBranding) - Requirement 1.3
    // Expected FAIL: account-created HTML says "R/E Pro Dashboard".
    // ---------------------------------------------------------------------
    public function test_no_in_set_body_uses_non_canonical_brand_name(): void
    {
        $violations = [];

        foreach ($this->inSetEmailTemplates() as $slug => $template) {
            if (str_contains((string) $template->body_html, self::NON_CANONICAL_BRAND)) {
                $violations[] = "{$slug} (body_html)";
            }
            if (str_contains((string) $template->body_text, self::NON_CANONICAL_BRAND)) {
                $violations[] = "{$slug} (body_text)";
            }
        }

        $this->assertSame(
            [],
            $violations,
            'No in-set template may reference the non-canonical brand "' . self::NON_CANONICAL_BRAND
            . '". Offending templates: ' . implode(', ', $violations)
        );
    }

    // ---------------------------------------------------------------------
    // Check 2: Placeholder links (hasBrokenOrPlaceholderLink) - Requirement 1.5
    // Expected FAIL: shoot-requested, shoot-request-approved,
    // shoot-request-modified, shoot-reminder, shoot-ready, shoot-summary.
    // ---------------------------------------------------------------------
    public function test_no_in_set_body_html_contains_placeholder_link(): void
    {
        $violations = [];

        foreach ($this->inSetEmailTemplates() as $slug => $template) {
            if (str_contains((string) $template->body_html, 'href="#"')) {
                $violations[] = $slug;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'No in-set body_html may contain a placeholder href="#" link. Offending templates: '
            . implode(', ', $violations)
        );
    }

    // ---------------------------------------------------------------------
    // Check 3: Hardcoded URL (usesInconsistentContactDetails) - Requirement 1.4
    // Expected FAIL: account-created hardcodes https://reprophotos.com inline
    // where the [portal_url]/site token should be used.
    // ---------------------------------------------------------------------
    public function test_no_in_set_body_hardcodes_brand_site_url(): void
    {
        $violations = [];

        foreach ($this->inSetEmailTemplates() as $slug => $template) {
            if (str_contains((string) $template->body_html, self::BRAND_SITE)) {
                $violations[] = "{$slug} (body_html)";
            }
        }

        $this->assertSame(
            [],
            $violations,
            'No in-set body should hardcode the literal brand site URL "' . self::BRAND_SITE
            . '" where a token exists. Offending templates: ' . implode(', ', $violations)
        );
    }

    // ---------------------------------------------------------------------
    // Check 4: Shared-content consistency (hasInconsistentSharedContent) - Req 1.6
    // The cancellation-policy and property-prep copy must be represented ONE
    // canonical way across the set. Expected FAIL: tokenized in
    // shoot-scheduled/shoot-updated but hand-written inline in
    // shoot-requested/shoot-request-approved/shoot-request-modified/shoot-reminder.
    // ---------------------------------------------------------------------
    public function test_shared_cancellation_and_prep_content_is_represented_one_canonical_way(): void
    {
        $templates = $this->inSetEmailTemplates();

        // Cancellation policy: tokenized vs inline literal copy.
        $cancellationTokenized = [];
        $cancellationInline = [];
        // Property-prep guidance: tokenized vs inline literal copy.
        $prepTokenized = [];
        $prepInline = [];

        foreach ($templates as $slug => $template) {
            $html = (string) $template->body_html;
            $text = (string) $template->body_text;
            $both = $html . "\n" . $text;

            if (str_contains($both, '{{cancellation_policy_html}}') || str_contains($both, '{{cancellation_policy_text}}')) {
                $cancellationTokenized[] = $slug;
            }
            if (str_contains($both, 'cancellation fee of $60')) {
                $cancellationInline[] = $slug;
            }

            if (str_contains($both, '{{property_prep_html}}') || str_contains($both, '{{property_prep_text}}')) {
                $prepTokenized[] = $slug;
            }
            if (str_contains($both, 'please have the property ready')) {
                $prepInline[] = $slug;
            }
        }

        $cancellationMixed = $cancellationTokenized !== [] && $cancellationInline !== [];
        $prepMixed = $prepTokenized !== [] && $prepInline !== [];

        $this->assertFalse(
            $cancellationMixed,
            'The cancellation policy must be represented one canonical way across the set, but it is '
            . 'tokenized in [' . implode(', ', $cancellationTokenized) . '] and hardcoded inline in ['
            . implode(', ', $cancellationInline) . '].'
        );

        $this->assertFalse(
            $prepMixed,
            'The property-prep guidance must be represented one canonical way across the set, but it is '
            . 'tokenized in [' . implode(', ', $prepTokenized) . '] and hardcoded inline in ['
            . implode(', ', $prepInline) . '].'
        );
    }

    // ---------------------------------------------------------------------
    // Check 5: Legacy wrapper cleanup - Req 1.7
    // Seeded DB bodies are content fragments. The shared renderer owns the
    // brand header/footer chrome, so bodies must not store legacy wrapper
    // markup that can be nested into rendered emails.
    // ---------------------------------------------------------------------
    public function test_every_in_set_email_body_html_is_free_of_legacy_wrapper_chrome(): void
    {
        $legacyHeaders = [];
        $legacyFooters = [];
        $legacyDividers = [];

        foreach ($this->inSetEmailTemplates() as $slug => $template) {
            $html = (string) $template->body_html;

            if (str_contains($html, 'email-header')) {
                $legacyHeaders[] = $slug;
            }
            if (str_contains($html, 'email-footer')) {
                $legacyFooters[] = $slug;
            }
            if (str_contains($html, 'border-bottom: 2px solid #007bff')) {
                $legacyDividers[] = $slug;
            }
        }

        $this->assertSame(
            [],
            $legacyHeaders,
            'Seeded body_html must not carry legacy email-header chrome. Found in: ' . implode(', ', $legacyHeaders)
        );

        $this->assertSame(
            [],
            $legacyFooters,
            'Seeded body_html must not carry legacy email-footer chrome. Found in: ' . implode(', ', $legacyFooters)
        );

        $this->assertSame(
            [],
            $legacyDividers,
            'Seeded body_html must not carry the old blue legacy divider. Found in: ' . implode(', ', $legacyDividers)
        );
    }

    // ---------------------------------------------------------------------
    // Check 6: HTML/text agreement (htmlAndTextDiverge) - Requirements 1.2, 1.9
    // Brand name, contact details, and field labels must match between
    // body_html and body_text. Expected FAIL: account-created brand mismatch,
    // and label terminology drift (e.g. "Scheduled Date" vs "Scheduled Shoot
    // Date", "Total" vs "Shoot total").
    // ---------------------------------------------------------------------
    public function test_account_created_html_and_text_agree_on_brand(): void
    {
        $template = MessageTemplate::query()->where('slug', 'account-created')->firstOrFail();
        $html = (string) $template->body_html;
        $text = (string) $template->body_text;

        // The plain text uses the canonical brand; the HTML must agree.
        $this->assertStringContainsString(
            self::BRAND_NAME,
            $text,
            'Baseline: account-created plain text is expected to use the canonical brand name.'
        );

        $this->assertStringContainsString(
            self::BRAND_NAME,
            $html,
            'account-created body_html must use the same canonical brand name as its body_text.'
        );

        $this->assertStringNotContainsString(
            self::NON_CANONICAL_BRAND,
            $html,
            'account-created body_html must not diverge from its body_text by using "' . self::NON_CANONICAL_BRAND . '".'
        );
    }

    public function test_in_set_field_labels_agree_between_html_and_text(): void
    {
        // For each template, a field label used in the plain text must also be
        // used (verbatim) in the HTML for the same concept. Drifted label pairs
        // cause divergence today.
        $labelPairs = [
            // slug => [textLabel => htmlLabelThatMustAlsoBePresent]
            'shoot-scheduled' => [
                'Scheduled Shoot Date:' => 'Scheduled Shoot Date:',
                'Scheduled Shoot Time:' => 'Scheduled Shoot Time:',
                'Shoot total:' => 'Shoot total:',
            ],
            'shoot-request-approved' => [
                'Scheduled Shoot Date:' => 'Scheduled Shoot Date:',
                'Scheduled Shoot Time:' => 'Scheduled Shoot Time:',
            ],
        ];

        $violations = [];

        foreach ($labelPairs as $slug => $pairs) {
            $template = MessageTemplate::query()->where('slug', $slug)->first();
            $this->assertNotNull($template, "Expected in-set template '{$slug}' to be seeded.");

            $html = (string) $template->body_html;
            $text = (string) $template->body_text;

            foreach ($pairs as $textLabel => $htmlLabel) {
                if (str_contains($text, $textLabel) && !str_contains($html, $htmlLabel)) {
                    $violations[] = "{$slug}: text uses '{$textLabel}' but body_html does not";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Field labels must match between body_html and body_text for the same concept. Divergences: '
            . implode(' | ', $violations)
        );
    }

    // ---------------------------------------------------------------------
    // Set-level coherence (formsCoherentJourney(shootSet')) - Requirement 2.8
    //
    // Reads the corrected in-set EMAIL templates in recipient-journey order and
    // asserts the set reads as ONE coherent communication journey:
    //   (a) every body is free of legacy wrapper chrome,
    //   (b) no body contains the non-canonical brand or a placeholder
    //       href="#" link,
    //   (c) the shared cancellation / property-prep copy reads identically
    //       wherever it is inlined (and no pre-fix divergent variant survives).
    //
    // This is the set-level companion to the per-template fix-checking above:
    // the per-template checks confirm each message is individually correct;
    // this confirms the end-to-end set is mutually consistent. It is expected
    // to PASS only after the central single-source-of-truth fix.
    // ---------------------------------------------------------------------
    public function test_in_set_email_templates_form_a_coherent_journey(): void
    {
        $templates = $this->inSetEmailTemplates();

        $legacyChrome = [];
        $usesNonCanonicalBrand = [];
        $hasPlaceholderLink = [];
        $divergentCancellation = [];
        $divergentPrep = [];

        // Iterate strictly in recipient-journey order (the declared in-set
        // order) so the assertion reflects reading the set end-to-end.
        foreach (self::IN_SET_EMAIL_SLUGS as $slug) {
            $template = $templates[$slug];
            $html = (string) $template->body_html;
            $text = (string) $template->body_text;
            $both = $html . "\n" . $text;

            // (a) The renderer owns shared brand/support chrome.
            if (str_contains($html, 'email-header')
                || str_contains($html, 'email-footer')
                || str_contains($html, 'border-bottom: 2px solid #007bff')) {
                $legacyChrome[] = $slug;
            }

            // (b) No non-canonical brand and no placeholder link anywhere.
            if (str_contains($both, self::NON_CANONICAL_BRAND)) {
                $usesNonCanonicalBrand[] = $slug;
            }
            if (str_contains($html, 'href="#"')) {
                $hasPlaceholderLink[] = $slug;
            }

            // (c) Shared copy reads identically wherever inlined. A template
            // either defers to the runtime token (no literal copy) or inlines
            // the ONE canonical wording; the pre-fix divergent variants must be
            // gone, and any inlined copy must contain the canonical sentence.
            if (str_contains($both, self::NON_CANONICAL_CANCELLATION)) {
                $divergentCancellation[] = "{$slug} (pre-fix variant)";
            }
            // The token form ({{cancellation_policy_*}}) does not contain the
            // literal phrase "cancellation fee", so this only matches inlined copy.
            if (str_contains($both, 'cancellation fee')
                && !str_contains($both, self::CANONICAL_CANCELLATION_SENTENCE)) {
                $divergentCancellation[] = "{$slug} (non-canonical inline wording)";
            }

            if (str_contains($both, self::NON_CANONICAL_PREP)) {
                $divergentPrep[] = "{$slug} (pre-fix variant)";
            }
            // Inlined property-prep copy (token form contains "property_prep",
            // not "the property is ready") must use the canonical sentence.
            if (str_contains($both, 'the property is ready')
                && !str_contains($both, self::CANONICAL_PREP_SENTENCE)) {
                $divergentPrep[] = "{$slug} (non-canonical inline wording)";
            }
        }

        $this->assertSame([], $legacyChrome,
            'Coherent journey (2.8a): seeded body fragments must not carry legacy email chrome. Offending: '
            . implode(', ', $legacyChrome));

        $this->assertSame([], $usesNonCanonicalBrand,
            'Coherent journey (2.8b): no in-set email may reference the non-canonical brand "'
            . self::NON_CANONICAL_BRAND . '". Offending: ' . implode(', ', $usesNonCanonicalBrand));

        $this->assertSame([], $hasPlaceholderLink,
            'Coherent journey (2.8b): no in-set email may contain a placeholder href="#" link. Offending: '
            . implode(', ', $hasPlaceholderLink));

        $this->assertSame([], $divergentCancellation,
            'Coherent journey (2.8c): the shared cancellation policy must read identically wherever it '
            . 'appears. Divergences: ' . implode(' | ', $divergentCancellation));

        $this->assertSame([], $divergentPrep,
            'Coherent journey (2.8c): the shared property-prep copy must read identically wherever it '
            . 'appears. Divergences: ' . implode(' | ', $divergentPrep));
    }
}
