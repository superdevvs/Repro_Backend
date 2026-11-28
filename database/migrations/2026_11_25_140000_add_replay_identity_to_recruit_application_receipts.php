<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Producer replay: remember what a website application reference carried the
 * first time, so a replay with other fields or other attachment bytes can be
 * refused instead of silently accepted. Nullable: receipts written before this
 * migration have no recorded identity and are routed to review on replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruit_application_receipts', function (Blueprint $table) {
            $table->char('payload_sha256', 64)->nullable();
            $table->text('attachment_sha256s')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recruit_application_receipts', function (Blueprint $table) {
            $table->dropColumn(['payload_sha256', 'attachment_sha256s']);
        });
    }
};
