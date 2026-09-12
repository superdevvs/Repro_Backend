<?php

use App\Services\SystemEmails\DirectEmailTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DirectEmailTemplates::installMissing();
    }

    public function down(): void
    {
        // Email copy becomes operational data once edited; rollback keeps it.
    }
};
