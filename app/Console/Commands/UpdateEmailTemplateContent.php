<?php

namespace App\Console\Commands;

use App\Models\MessageTemplate;
use Illuminate\Console\Command;

/**
 * One-time, idempotent fix-up for existing message_templates rows.
 *
 * Seeder edits only affect fresh installs; existing environments keep their
 * stored body_html / body_text. This command applies the same content changes
 * (support line wording, corrected phone, New Account closing) to live rows
 * without clobbering unrelated customizations. Running it repeatedly is safe.
 */
class UpdateEmailTemplateContent extends Command
{
    protected $signature = 'email:update-template-content {--dry-run : Show what would change without saving}';

    protected $description = 'Idempotently update existing email templates (support line, phone, New Account closing)';

    /**
     * Replacements applied to every EMAIL template's stored content. Tokens are
     * stored in {{...}} form after seeding, but bracket [..] forms are handled
     * too for safety. Each pair is idempotent: once applied, the search string
     * no longer matches.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $globalReplacements = [
        ['202-868-1113', '202-868-1663'],
        ['(202) 868-1113', '(202) 868-1663'],
        ['or email {{company_email}}.', 'or email us at {{company_email}}.'],
        ['or email [company_email].', 'or email us at [company_email].'],
    ];

    /**
     * Replacements applied only to the New Account (account-created) template.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $accountCreatedReplacements = [
        ['Thanks for the opportunity to provide you with outstanding real estate marketing services!', 'Thank you for the opportunity.'],
        ['Thank you for the opportunity to elevate your real estate marketing!', 'Thank you for the opportunity.'],
        ['Thanks for the opportunity to elevate your real estate marketing!', 'Thank you for the opportunity.'],
        ['Thank you for the opportunity to elevate your real estate marketing.', 'Thank you for the opportunity.'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('=== Updating existing email template content ===');
        if ($dryRun) {
            $this->warn('DRY RUN: no changes will be saved.');
        }

        $emailTemplates = MessageTemplate::query()
            ->where('channel', 'EMAIL')
            ->get();

        $changed = 0;

        foreach ($emailTemplates as $template) {
            $replacements = $this->globalReplacements;
            if ($template->slug === 'account-created') {
                $replacements = array_merge($replacements, $this->accountCreatedReplacements);
            }

            $original = [
                'subject' => (string) $template->subject,
                'body_html' => (string) $template->body_html,
                'body_text' => (string) $template->body_text,
            ];

            $updated = $original;
            foreach (['subject', 'body_html', 'body_text'] as $field) {
                foreach ($replacements as [$search, $replace]) {
                    $updated[$field] = str_replace($search, $replace, $updated[$field]);
                }
            }

            if ($updated === $original) {
                continue;
            }

            $changed++;
            $this->line("  ~ {$template->slug} (#{$template->id})");

            if (! $dryRun) {
                $template->forceFill($updated)->save();
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Would update {$changed} template(s)."
            : "Updated {$changed} template(s).");

        return self::SUCCESS;
    }
}
