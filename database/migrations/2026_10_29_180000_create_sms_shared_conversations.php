<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_shared_groups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('sms_number_id')->constrained('sms_numbers')->restrictOnDelete();
            $t->string('business_number', 20);
            $t->char('participant_key', 64)->unique();
            $t->json('participants');
            $t->string('title', 100);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('last_message_at')->nullable()->index();
            $t->string('last_snippet', 200)->default('');
            $t->timestamps();
        });
        Schema::create('sms_shared_group_members', function (Blueprint $t) {
            $t->uuid('group_id');
            $t->foreign('group_id')->references('id')->on('sms_shared_groups')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedBigInteger('last_read_sequence')->default(0);
            $t->uuid('last_read_message_id')->nullable();
            $t->primary(['group_id', 'user_id']);
        });
        Schema::create('sms_shared_messages', function (Blueprint $t) {
            // Public IDs remain UUIDs. A database-owned sequence gives read
            // cursors a total arrival order, including equal provider timestamps.
            $t->bigIncrements('sequence');
            $t->uuid('id')->unique();
            $t->uuid('group_id');
            $t->foreign('group_id')->references('id')->on('sms_shared_groups')->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('request_key')->nullable();
            $t->unique(['created_by', 'request_key']);
            $t->char('request_hash', 64)->nullable();
            $t->string('direction', 10);
            $t->string('from_number', 20);
            $t->text('body_text');
            $t->string('status', 20);
            $t->string('provider_message_id', 128)->nullable()->unique();
            $t->string('provider_group_message_id', 128)->nullable()->index();
            $t->json('recipient_statuses');
            $t->unsignedSmallInteger('media_count')->default(0);
            $t->timestamp('provider_attempted_at')->nullable();
            $t->timestamps();
            $t->index(['group_id', 'created_at']);
        });
        Schema::create('sms_shared_webhook_events', function (Blueprint $t) {
            $t->string('event_id', 128)->primary();
            $t->uuid('message_id');
            $t->foreign('message_id')->references('id')->on('sms_shared_messages')->cascadeOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('sms_shared_opt_outs', function (Blueprint $t) {
            $t->string('phone_number', 20)->primary();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_shared_opt_outs');
        Schema::dropIfExists('sms_shared_webhook_events');
        Schema::dropIfExists('sms_shared_messages');
        Schema::dropIfExists('sms_shared_group_members');
        Schema::dropIfExists('sms_shared_groups');
    }
};
