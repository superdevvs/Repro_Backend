<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->json('editor_document_json')->nullable();
        });
        Schema::create('email_template_artworks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('prompt');
            $table->string('status', 20)->default('queued');
            $table->string('path')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_artworks');
        Schema::table('message_templates', fn (Blueprint $table) => $table->dropColumn('editor_document_json'));
    }
};
