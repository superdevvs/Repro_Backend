<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table): void {
            $table->json('content_blocks_json')->nullable();
        });
        \App\Services\SystemEmails\EditableEmailContent::upgradeDefaultDescriptions();
    }

    public function down(): void
    {
        // Preserve saved copy overrides on operational rollback.
    }
};
