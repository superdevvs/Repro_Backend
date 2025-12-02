<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Contacts - Unified contact list
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('type')->default('other'); // client, photographer, rep, other
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_id')->nullable(); // For linking to specific accounts if needed
            $table->timestamps();
        });

        // 2. Message Channels - Configured providers
        Schema::create('message_channels', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // EMAIL, SMS
            $table->string('provider'); // LOCAL_SMTP, WP_MAIL_SMTP, HOSTING_SMTP, MAILCHIMP, MIGHTYCALL
            $table->string('display_name');
            $table->string('from_email')->nullable();
            $table->string('from_number')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('owner_scope')->default('GLOBAL'); // GLOBAL, ACCOUNT, USER
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->text('config_json')->nullable(); // Encrypted config
            $table->timestamps();
            
            $table->index(['type', 'owner_scope', 'owner_id']);
        });

        // 3. SMS Numbers - Specific to MightyCall or other SMS providers
        Schema::create('sms_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('mighty_call_key')->nullable();
            $table->string('phone_number');
            $table->string('label')->nullable();
            $table->string('owner_type')->default('GLOBAL'); // GLOBAL, USER, TEAM
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // 4. Message Templates
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('channel'); // EMAIL, SMS
            $table->string('name');
            $table->string('subject')->nullable();
            $table->text('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->text('variables_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope')->default('GLOBAL'); // GLOBAL, ACCOUNT, USER
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['channel', 'scope']);
        });

        // 5. Message Threads
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->string('channel'); // EMAIL, SMS
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_direction')->nullable();
            $table->text('last_snippet')->nullable();
            $table->text('unread_for_user_ids_json')->nullable();
            $table->timestamps();

            $table->index(['channel', 'last_message_at']);
        });

        // 6. Messages
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('channel'); // EMAIL, SMS
            $table->string('direction'); // OUTBOUND, INBOUND
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->foreignId('message_channel_id')->nullable()->constrained('message_channels')->nullOnDelete();
            $table->string('from_address')->nullable();
            $table->string('to_address');
            $table->string('subject')->nullable();
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->string('status')->default('QUEUED'); // QUEUED, SCHEDULED, SENT, DELIVERED, FAILED, CANCELLED
            $table->text('error_message')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('related_shoot_id')->nullable(); // constrained later if needed, or loose coupling
            $table->foreignId('related_account_id')->nullable(); // Client/Account
            $table->foreignId('thread_id')->nullable()->constrained('message_threads')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel', 'status', 'scheduled_at']);
            $table->index(['thread_id']);
            $table->index(['related_shoot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('sms_numbers');
        Schema::dropIfExists('message_channels');
        Schema::dropIfExists('contacts');
    }
};

