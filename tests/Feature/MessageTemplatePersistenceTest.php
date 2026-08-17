<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\SystemEmails\ProtectedAutomationEmailMap;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplatePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const OVERRIDE_MIGRATION =
        'migrations/2026_08_16_000001_backfill_protected_email_template_overrides.php';

    public function test_system_seeder_rerun_preserves_admin_copy_and_custom_system_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $seeder = app(MessagingSystemSeeder::class);
        $seeder->run();

        $template = MessageTemplate::query()
            ->where('slug', 'shoot-on-hold')
            ->firstOrFail();

        $template->forceFill([
            'channel' => 'SMS',
            'name' => 'Changed structural name',
            'description' => 'Admin-authored description',
            'category' => 'GENERAL',
            'subject' => 'Admin-authored subject {{shoot_location}}',
            'body_html' => '<p>Admin-authored HTML {{custom_variable}}</p>',
            'body_text' => 'Admin-authored text {{custom_variable}}',
            'variables_json' => ['custom_variable'],
            'scope' => 'USER',
            'is_system' => false,
            'is_active' => false,
            'updated_by' => $admin->id,
        ])->save();

        $custom = MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Custom System Notice',
            'slug' => 'custom-system-notice',
            'description' => 'A production-only system template',
            'category' => 'GENERAL',
            'subject' => 'Custom system subject',
            'body_html' => '<p>Custom system body</p>',
            'body_text' => 'Custom system body',
            'variables_json' => ['custom_value'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'updated_by' => $admin->id,
        ]);

        $seeder->run();

        $template->refresh();
        $this->assertSame('EMAIL', $template->channel);
        $this->assertSame('Shoot On Hold', $template->name);
        $this->assertSame('BOOKING', $template->category);
        $this->assertSame('SYSTEM', $template->scope);
        $this->assertTrue($template->is_system);

        $this->assertSame('Admin-authored description', $template->description);
        $this->assertSame('Admin-authored subject {{shoot_location}}', $template->subject);
        $this->assertSame('<p>Admin-authored HTML {{custom_variable}}</p>', $template->body_html);
        $this->assertSame('Admin-authored text {{custom_variable}}', $template->body_text);
        $this->assertFalse($template->is_active);
        $this->assertSame($admin->id, $template->updated_by);
        $this->assertContains('shoot_location', $template->variables_json);
        $this->assertContains('custom_variable', $template->variables_json);

        $this->assertDatabaseHas('message_templates', [
            'id' => $custom->id,
            'slug' => 'custom-system-notice',
            'subject' => 'Custom system subject',
            'body_html' => '<p>Custom system body</p>',
            'is_system' => true,
        ]);
    }

    public function test_fresh_invoice_reminder_default_captures_current_live_copy(): void
    {
        MessageTemplate::query()->where('slug', 'payment-due-reminder')->delete();

        app(MessagingSystemSeeder::class)->run();

        $template = MessageTemplate::query()
            ->where('slug', 'payment-due-reminder')
            ->firstOrFail();

        $this->assertSame(
            'Payment Reminder - {{shoot_location}} - Invoice {{invoice_number}}',
            $template->subject
        );
        $this->assertSame(
            'Payment reminder for pending balance for {{shoot_location}}.',
            $template->description
        );
        $this->assertStringContainsString('{{shoot_address}}', $template->body_html);
        $this->assertStringContainsString('{{shoot_address}}', $template->body_text);
        $this->assertContains('shoot_location', $template->variables_json);
        $this->assertContains('shoot_address', $template->variables_json);
    }

    public function test_guarded_migration_maps_and_activates_only_admin_edited_system_templates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $edited = MessageTemplate::query()->where('slug', 'account-created')->firstOrFail();
        $edited->forceFill([
            'subject' => 'Production-edited account subject',
            'body_html' => '<p>Production-edited account body</p>',
            'body_text' => 'Production-edited account body',
            'description' => 'Production-edited description',
            'email_type' => null,
            'override_enabled' => false,
            'updated_by' => $admin->id,
        ])->save();

        $untouched = MessageTemplate::query()->where('slug', 'shoot-scheduled')->firstOrFail();
        $untouched->forceFill([
            'email_type' => null,
            'override_enabled' => false,
            'updated_by' => null,
        ])->save();

        $explicitOptOut = MessageTemplate::query()->where('slug', 'shoot-reminder')->firstOrFail();
        $explicitOptOut->forceFill([
            'email_type' => 'SHOOT_REMINDER',
            'override_enabled' => false,
            'updated_by' => $admin->id,
        ])->save();

        $notSystem = MessageTemplate::query()->where('slug', 'shoot-cancelled')->firstOrFail();
        $notSystem->forceFill([
            'is_system' => false,
            'email_type' => null,
            'override_enabled' => false,
            'updated_by' => $admin->id,
        ])->save();

        $editedContent = $edited->only([
            'subject',
            'body_html',
            'body_text',
            'description',
            'is_active',
        ]);

        $migration = $this->loadOverrideMigration();
        $migration->up();
        $migration->up();

        $edited->refresh();
        $untouched->refresh();
        $explicitOptOut->refresh();
        $notSystem->refresh();

        $this->assertSame('ACCOUNT_CREATED', $edited->email_type);
        $this->assertTrue($edited->override_enabled);
        $this->assertSame($editedContent, $edited->only(array_keys($editedContent)));

        $this->assertNull($untouched->email_type);
        $this->assertFalse($untouched->override_enabled);

        $this->assertSame('SHOOT_REMINDER', $explicitOptOut->email_type);
        $this->assertFalse($explicitOptOut->override_enabled);

        $this->assertNull($notSystem->email_type);
        $this->assertFalse($notSystem->override_enabled);
    }

    public function test_canonical_template_map_has_one_seeded_slug_per_protected_alias(): void
    {
        $map = app(ProtectedAutomationEmailMap::class)->canonicalTemplateSlugToAlias();

        $this->assertNotEmpty($map);
        $this->assertCount(count($map), array_unique(array_values($map)));

        foreach ($map as $slug => $alias) {
            $this->assertSame(
                $alias,
                app(ProtectedAutomationEmailMap::class)->canonicalAliasForTemplateSlug(strtoupper($slug))
            );
            $this->assertDatabaseHas('message_templates', [
                'slug' => $slug,
                'is_system' => true,
            ]);
        }
    }

    private function loadOverrideMigration(): Migration
    {
        $migration = require database_path(self::OVERRIDE_MIGRATION);

        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
