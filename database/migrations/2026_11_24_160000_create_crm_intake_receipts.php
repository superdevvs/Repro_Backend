<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_website_intake_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('reference', 120);
            $table->text('payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'reference'], 'crm_website_receipt_identity');
            $table->index(['completed_at', 'id'], 'crm_website_receipt_pending');
        });

        Schema::create('recruit_application_receipts', function (Blueprint $table) {
            $table->string('reference', 120)->primary();
            $table->unsignedBigInteger('applicant_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('recruit_applicant_files', function (Blueprint $table) {
            $table->char('intake_checksum', 64)->nullable();
            $table->unique(['applicant_id', 'kind', 'intake_checksum'], 'recruit_file_intake_identity');
        });
    }

    public function down(): void
    {
        Schema::table('recruit_applicant_files', function (Blueprint $table) {
            $table->dropUnique('recruit_file_intake_identity');
            $table->dropColumn('intake_checksum');
        });
        Schema::dropIfExists('recruit_application_receipts');
        Schema::dropIfExists('crm_website_intake_receipts');
    }
};
