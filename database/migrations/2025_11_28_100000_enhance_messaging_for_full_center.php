<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add missing fields to message_templates
        Schema::table('message_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('message_templates', 'slug')) {
                $table->string('slug')->nullable()->after('name');
            }
            if (!Schema::hasColumn('message_templates', 'description')) {
                $table->text('description')->nullable()->after('slug');
            }
            if (!Schema::hasColumn('message_templates', 'category')) {
                $table->string('category')->nullable()->after('description'); // BOOKING, REMINDER, PAYMENT, INVOICE, ACCOUNT, GENERAL
            }
            if (!Schema::hasColumn('message_templates', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_system');
            }
            if (!Schema::hasColumn('message_templates', 'owner_id')) {
                $table->unsignedBigInteger('owner_id')->nullable()->after('scope');
            }
        });

        // Add missing fields to messages
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'template_id')) {
                $table->foreignId('template_id')->nullable()->after('created_by')->constrained('message_templates')->nullOnDelete();
            }
            if (!Schema::hasColumn('messages', 'related_invoice_id')) {
                $table->foreignId('related_invoice_id')->nullable()->after('related_account_id');
            }
            if (!Schema::hasColumn('messages', 'send_source')) {
                $table->string('send_source')->default('MANUAL')->after('status'); // MANUAL, AUTOMATION, SYSTEM
            }
            if (!Schema::hasColumn('messages', 'reply_to_email')) {
                $table->string('reply_to_email')->nullable()->after('to_address');
            }
            if (!Schema::hasColumn('messages', 'attachments_json')) {
                $table->text('attachments_json')->nullable()->after('body_html');
            }
        });

        // Create automation_rules table
        if (!Schema::hasTable('automation_rules')) {
            Schema::create('automation_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('trigger_type'); // ACCOUNT_CREATED, SHOOT_BOOKED, etc.
                $table->boolean('is_active')->default(true);
                $table->string('scope')->default('GLOBAL'); // SYSTEM, GLOBAL, ACCOUNT, USER
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->foreignId('template_id')->nullable()->constrained('message_templates')->nullOnDelete();
                $table->foreignId('channel_id')->nullable()->constrained('message_channels')->nullOnDelete();
                $table->text('condition_json')->nullable(); // JSON filter expression
                $table->text('schedule_json')->nullable(); // Offsets, cron-like for reminders
                $table->text('recipients_json')->nullable(); // Which recipient types: client, photographer, admin, rep
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['trigger_type', 'is_active']);
            });
        }

        // Add message tags/labels support
        if (!Schema::hasColumn('messages', 'tags_json')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->text('tags_json')->nullable()->after('send_source');
            });
        }

        // Add reply_to to message_channels
        if (!Schema::hasColumn('message_channels', 'reply_to_email')) {
            Schema::table('message_channels', function (Blueprint $table) {
                $table->string('reply_to_email')->nullable()->after('from_email');
            });
        }
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'template_id',
                'related_invoice_id',
                'send_source',
                'reply_to_email',
                'attachments_json',
                'tags_json'
            ]);
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn([
                'slug',
                'description',
                'category',
                'is_active',
                'owner_id'
            ]);
        });

        Schema::table('message_channels', function (Blueprint $table) {
            $table->dropColumn('reply_to_email');
        });

        Schema::dropIfExists('automation_rules');
    }
};

