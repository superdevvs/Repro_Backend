<?php

use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-runs MessagingSystemSeeder so production picks up template changes that
 * were made in the seeder source after the previous refresh migrations had
 * already run (Laravel runs each migration only once).
 *
 * This resolves two stale-production-data QA findings:
 *  - #12: seeds the four manual-notification templates that the "Send
 *    notification" dialog resolves — shoot-on-hold, shoot-cancelled,
 *    payment-due, payment-receipt — which were missing in prod and caused
 *    "No query results for model [MessageTemplate]" on preview/send.
 *  - #24: updates the `account-created` template to the corrected body whose
 *    dashboard anchor text matches its href and uses a single consistent URL
 *    ([portal_url]) plus the canonical closing line.
 *
 * The seeder is idempotent — every template is written via
 * MessageTemplate::updateOrCreate(['slug' => ...]) — so this safely CREATES the
 * missing manual templates and UPDATES existing system templates to the current
 * canonical source without duplicating rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(MessagingSystemSeeder::class)->run();
    }

    public function down(): void
    {
        // This refresh migration is intentionally non-destructive.
    }
};
