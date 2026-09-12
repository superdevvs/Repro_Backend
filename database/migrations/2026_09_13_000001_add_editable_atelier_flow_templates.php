<?php

use App\Services\SystemEmails\EmailAtelierTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Add only missing rows; never reset customer edits, disabled states or automations.
        EmailAtelierTemplates::installMissing();
    }

    public function down(): void
    {
        // Preserve templates that may have been customized after this release.
    }
};
