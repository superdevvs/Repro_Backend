<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_template_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source')->default('uploaded');
            $table->uuid('artwork_id')->nullable()->unique();
            $table->string('status')->default('processing');
            $table->string('original_path')->nullable();
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedInteger('original_bytes')->default(0);
            $table->unsignedInteger('bytes')->default(0);
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->json('options')->nullable();
            $table->string('error')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'archived', 'created_at']);
        });
        // Keep existing generated artwork discoverable without moving URLs used
        // by previously saved/sent emails. A new rendition imports its public
        // image into the private originals pipeline on first reuse.
        \Illuminate\Support\Facades\DB::table('email_template_assets')->insertUsing(
            ['id','artwork_id','user_id','name','source','status','path','mime','created_at','updated_at'],
            \Illuminate\Support\Facades\DB::table('email_template_artworks')->where('status', 'completed')->whereNotNull('path')->select([
                'id','id as artwork_id','user_id', \Illuminate\Support\Facades\DB::raw('substr(prompt, 1, 255)'),
                \Illuminate\Support\Facades\DB::raw("'generated'"), \Illuminate\Support\Facades\DB::raw("'ready'"), 'path',
                \Illuminate\Support\Facades\DB::raw("'image/png'"), 'created_at','updated_at',
            ])
        );
        Schema::create('email_template_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('template_id')->nullable()->index();
            // A nullable template_id alone cannot enforce uniqueness for a new
            // email in SQLite. Claim one canonical recovery slot per workspace.
            $table->string('workspace_key');
            $table->unsignedInteger('revision')->default(1);
            $table->json('draft');
            $table->timestamps();
            $table->unique(['user_id', 'workspace_key']);
        });
        Schema::create('email_template_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('snapshot');
            $table->timestamps();
        });
        Schema::create('email_template_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('section');
            $table->timestamps();
        });
        Schema::table('message_templates', fn (Blueprint $table) => $table->unsignedInteger('workspace_revision')->default(0));
    }

    public function down(): void
    {
        Schema::table('message_templates', fn (Blueprint $table) => $table->dropColumn('workspace_revision'));
        foreach (['email_template_sections', 'email_template_revisions', 'email_template_drafts', 'email_template_assets'] as $table) Schema::dropIfExists($table);
    }
};
