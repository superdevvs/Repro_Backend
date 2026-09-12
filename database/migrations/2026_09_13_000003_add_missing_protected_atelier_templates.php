<?php

use App\Services\SystemEmails\ProtectedEmailTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ProtectedEmailTemplates::installMissing();
    }

    public function down(): void
    {
        // Retain user edits. Overrides remain explicitly opt-in.
    }
};
