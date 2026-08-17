<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\SystemEmails\EmailTypeRegistry;
use App\Services\SystemEmails\SystemEmailBuilder;
use App\Services\SystemEmails\SystemEmailRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2: protected (Blade) automated emails can be overridden by a DB template
 * once an admin opts in. Disabled by default => the hardcoded Blade view is used.
 */
class ProtectedEmailOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'recipient' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'company_name' => 'Acme Realty',
                'phonenumber' => '555-1212',
            ],
            'account' => ['company_name' => 'Acme Realty'],
            'branding' => [
                'product_name' => 'R/E Pro Photos',
                'support_email' => 'contact@reprophotos.com',
                'dashboard_url' => 'https://reprodashboard.com',
            ],
            'links' => ['dashboard' => 'https://reprodashboard.com'],
            'meta' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function shootPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'location' => '123 Example Street, Tampa, FL, 33602',
            'date' => 'Aug 20, 2026 at 2:30 PM',
            'time' => '2:30 PM',
            'status' => 'requested',
            'client_name' => 'Jane Doe',
            'client_email' => 'jane@example.com',
            'primary_photographer' => 'Alex Morgan',
            'photographer' => 'Alex Morgan',
            'photographers' => ['Alex Morgan'],
            'photographers_label' => 'Alex Morgan',
            'services' => [],
            'property_highlights' => [],
            'access_details' => [],
            'notes_lines' => [],
            'company_notes_lines' => [],
            'photographer_notes_lines' => [],
            'dashboard_url' => 'https://reprodashboard.com',
        ], $overrides);
    }

    private function definition()
    {
        return app(EmailTypeRegistry::class)->definition('ACCOUNT_CREATED');
    }

    public function test_enabled_override_template_replaces_blade_output(): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Account Override',
            'slug' => 'account-created-override',
            'subject' => 'Welcome [client_first_name]',
            'body_html' => '<p>Custom override body for [client_first_name].</p>',
            'body_text' => 'Custom override body',
            'variables_json' => ['client_first_name'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'ACCOUNT_CREATED',
            'override_enabled' => true,
        ]);

        $result = app(SystemEmailRenderer::class)
            ->render($this->definition(), $this->payload(), 'Default Subject');

        $this->assertStringContainsString('Custom override body for Jane', $result['body_html']);
        $this->assertSame('Welcome Jane', $result['subject']);
    }

    public function test_builder_returns_the_enabled_override_subject(): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Account Override',
            'slug' => 'account-created-builder-override',
            'subject' => 'Admin-edited welcome for [client_first_name]',
            'body_html' => '<p>Builder override body for [client_first_name].</p>',
            'body_text' => 'Builder override body',
            'variables_json' => ['client_first_name'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'ACCOUNT_CREATED',
            'override_enabled' => true,
        ]);

        $result = app(SystemEmailBuilder::class)
            ->build('ACCOUNT_CREATED', $this->payload());

        $this->assertSame('Admin-edited welcome for Jane', $result['subject']);
        $this->assertStringContainsString('Builder override body for Jane', $result['body_html']);
    }

    public function test_disabled_override_falls_back_to_blade(): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Account Override',
            'slug' => 'account-created-override',
            'subject' => 'Welcome [client_first_name]',
            'body_html' => '<p>Custom override body for [client_first_name].</p>',
            'body_text' => 'Custom override body',
            'variables_json' => ['client_first_name'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'ACCOUNT_CREATED',
            'override_enabled' => false,
        ]);

        $result = app(SystemEmailRenderer::class)
            ->render($this->definition(), $this->payload(), 'Default Subject');

        $this->assertStringNotContainsString('Custom override body', $result['body_html']);
        // Blade hero eyebrow for the New Account email.
        $this->assertStringContainsString('Account Created', $result['body_html']);
        $this->assertSame('Default Subject', $result['subject']);
    }

    public function test_shoot_override_resolves_photographer_names_without_repeating_the_time(): void
    {
        $client = User::factory()->create(['name' => 'Jane Doe', 'role' => 'client']);
        $photographer = User::factory()->create(['name' => 'Alex Morgan', 'role' => 'photographer']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'address' => '123 Example Street',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
            'scheduled_date' => '2026-08-20',
            'time' => '14:30:00',
        ]);

        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Shoot Reminder Override',
            'slug' => 'shoot-reminder-override-photographer-regression',
            'subject' => 'Reminder with {{photographer_first_name}} {{photographer_last_name}}',
            'body_html' => '<p>{{photographer_name}} — {{shoot_date}} at {{shoot_time}}</p>',
            'body_text' => '{{photographer_name}} — {{shoot_date}} at {{shoot_time}}',
            'variables_json' => [
                'photographer_name',
                'photographer_first_name',
                'photographer_last_name',
                'shoot_date',
                'shoot_time',
            ],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'SHOOT_REMINDER',
            'override_enabled' => true,
        ]);

        $payload = $this->payload();
        $payload['recipient']['id'] = $client->id;
        $payload['account']['id'] = $client->id;
        $payload['shoot'] = $this->shootPayload(['id' => $shoot->id]);
        $payload['meta'] = ['recipient_type' => 'client'];

        $result = app(SystemEmailBuilder::class)->build('SHOOT_REMINDER', $payload);

        $this->assertSame('Reminder with Alex Morgan', $result['subject']);
        $this->assertStringContainsString('Alex Morgan — Aug 20, 2026 at 2:30 PM', $result['body_html']);
        $this->assertStringContainsString('Alex Morgan — Aug 20, 2026 at 2:30 PM', $result['body_text']);
        $this->assertStringNotContainsString('at 2:30 PM at 2:30 PM', $result['body_html'].$result['body_text']);
    }

    public function test_shoot_requested_admin_uses_protected_audience_copy_while_client_uses_override(): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Shoot Requested Client Override',
            'slug' => 'shoot-requested-client-audience-regression',
            'subject' => 'Client request receipt',
            'body_html' => '<p>Client-authored request copy.</p>',
            'body_text' => 'Client-authored request copy.',
            'variables_json' => [],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'SHOOT_REQUESTED',
            'override_enabled' => true,
        ]);

        $adminPayload = $this->payload();
        $adminPayload['recipient'] = [
            'first_name' => 'Admin',
            'last_name' => 'User',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ];
        $adminPayload['shoot'] = $this->shootPayload();
        $adminPayload['meta'] = [
            'recipient_type' => 'admin',
            'is_admin' => true,
        ];

        $adminResult = app(SystemEmailBuilder::class)->build('SHOOT_REQUESTED', $adminPayload);

        $this->assertSame('New Shoot Request Needs Review', $adminResult['subject']);
        $this->assertStringContainsString('A new shoot request needs review.', $adminResult['body_html']);
        $this->assertStringNotContainsString('Client-authored request copy.', $adminResult['body_html']);

        $clientPayload = $this->payload();
        $clientPayload['shoot'] = $this->shootPayload();
        $clientPayload['meta'] = [
            'recipient_type' => 'client',
            'is_admin' => false,
        ];

        $clientResult = app(SystemEmailBuilder::class)->build('SHOOT_REQUESTED', $clientPayload);

        $this->assertSame('Client request receipt', $clientResult['subject']);
        $this->assertStringContainsString('Client-authored request copy.', $clientResult['body_html']);
    }

    public function test_invoice_generated_override_receives_complete_production_variables(): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Weekly Invoice Override',
            'slug' => 'weekly-invoice-generated-override',
            'subject' => 'Invoice [invoice_number] ready for [billing_period]',
            'body_html' => '
                <p>[recipient_name], your [recipient_role] invoice is [invoice_status].</p>
                <p><span>Invoice Number:</span> [invoice_number]</p>
                <p>Total: [invoice_total]</p>
                <div>[invoice_items_html]</div>
                <a href="[dashboard_url]">Review invoice</a>
                <p>[invoice_next_step]</p>
                <p>[approval_note]</p>',
            'body_text' => "[recipient_name]\nInvoice Number: [invoice_number]\n[invoice_items_text]\n[dashboard_url]",
            'variables_json' => [
                'recipient_name',
                'recipient_role',
                'billing_period',
                'invoice_number',
                'invoice_status',
                'invoice_total',
                'invoice_items_html',
                'invoice_items_text',
                'dashboard_url',
                'invoice_next_step',
                'approval_note',
            ],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'INVOICE_GENERATED',
            'override_enabled' => true,
        ]);

        $payload = $this->payload();
        $payload['invoice'] = [
            'id' => 18,
            'invoice_number' => 'Invoice 00018',
            'status' => 'pending',
            'total_amount' => 275.5,
            'amount_paid' => 25.5,
            'items' => [[
                'type' => 'service',
                'description' => 'HDR Photography',
                'total_amount' => 275.5,
            ]],
        ];
        $payload['meta'] = [
            'recipient_type' => 'photographer',
            'recipient_role' => 'photographer',
            'period' => 'Aug 10 - Aug 16, 2026',
        ];

        $result = app(SystemEmailBuilder::class)->build('INVOICE_GENERATED', $payload);
        $visibleHtml = html_entity_decode(strip_tags($result['body_html']));

        $this->assertSame('Invoice 00018 ready for Aug 10 - Aug 16, 2026', $result['subject']);
        $this->assertStringContainsString('Jane Doe, your photographer invoice is Pending.', $result['body_html']);
        $this->assertStringContainsString('Invoice Number: 00018', $visibleHtml);
        $this->assertStringContainsString('$275.50', $result['body_html']);
        $this->assertStringContainsString('HDR Photography', $result['body_html']);
        $this->assertStringContainsString('https://reprodashboard.com', $result['body_html']);
        $this->assertStringContainsString('Invoice Number: 00018', $result['body_text']);
        $this->assertStringContainsString('HDR Photography (Service): $275.50', $result['body_text']);
        $this->assertStringNotContainsString('Invoice Invoice', $result['subject'].$result['body_html'].$result['body_text']);
        $this->assertDoesNotMatchRegularExpression('/\[(?:recipient|invoice|billing|dashboard|approval)[^\]]*\]/', $result['body_html'].$result['body_text']);
    }

    public function test_protected_invoice_blades_render_prefixed_numbers_without_repeating_invoice(): void
    {
        $payload = $this->payload();
        $payload['shoot'] = ['id' => 42];
        $payload['invoice'] = [
            'id' => 18,
            'invoice_number' => 'Invoice 00018',
            'status' => 'pending',
            'total' => 275.5,
            'total_amount' => 275.5,
            'amount_paid' => 0,
            'issue_date' => now()->subDay(),
            'due_date' => now()->addWeek(),
            'approved_at' => now(),
            'rejected_at' => now(),
            'modified_at' => now(),
            'modification_notes' => 'Mileage added.',
            'rejection_reason' => 'Please attach the receipt.',
            'items' => [[
                'type' => 'service',
                'description' => 'HDR Photography',
                'total_amount' => 275.5,
            ]],
        ];
        $payload['meta'] = [
            'recipient_type' => 'photographer',
            'recipient_role' => 'photographer',
            'period' => 'Aug 10 - Aug 16, 2026',
            'payee_name' => 'Jane Doe',
            'role_label' => 'photographer',
            'role_heading' => 'Photographer',
            'address' => '123 Example Street',
        ];

        foreach ([
            'INVOICE_GENERATED' => 'Invoice Number 00018',
            'INVOICE_PENDING_APPROVAL' => 'Invoice 00018',
            'INVOICE_APPROVED' => 'Invoice Number 00018',
            'INVOICE_REJECTED' => 'Invoice Number 00018',
            'CANCELLATION_FEE_INVOICE' => 'Invoice 00018',
        ] as $emailType => $expectedVisibleText) {
            $result = app(SystemEmailBuilder::class)->build($emailType, $payload);
            $visibleHtml = trim(preg_replace(
                '/\s+/',
                ' ',
                html_entity_decode(strip_tags(preg_replace('/>\s*</', '> <', $result['body_html']) ?? $result['body_html']))
            ) ?? '');

            $this->assertStringContainsString($expectedVisibleText, $visibleHtml, $emailType);
            $this->assertStringContainsString('00018', $result['body_text'], $emailType);
            $this->assertStringNotContainsString(
                'Invoice Invoice 00018',
                $result['subject']."\n".$visibleHtml."\n".$result['body_text'],
                $emailType
            );
        }
    }
}
