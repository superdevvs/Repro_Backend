<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_at_acceptance', 50);
            $table->string('document_key', 100);
            $table->string('document_version', 100);
            $table->string('content_hash', 64);
            $table->date('effective_date')->nullable();
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('audit_metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'document_key', 'document_version'], 'legal_acceptance_user_document_version_unique');
            $table->index(['document_key', 'document_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
