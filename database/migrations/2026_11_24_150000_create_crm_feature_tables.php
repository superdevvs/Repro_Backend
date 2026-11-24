<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Further CRM features: companies, monthly goals, quotes, Robbie proposals,
 * SMS consent and client health timestamps. References are indexed BIGINT
 * columns without foreign-key constraints, like the other CRM tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_companies')) {
            Schema::create('crm_companies', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->string('name_normalized', 160);
                $table->string('website', 190)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('state', 40)->nullable();
                $table->string('segment', 16)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique('name_normalized', 'crm_companies_name_uq');
            });
        }

        if (! Schema::hasTable('crm_goals')) {
            Schema::create('crm_goals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('month', 7);
                $table->string('metric', 32);
                $table->unsignedInteger('target');
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'month', 'metric'], 'crm_goals_user_month_metric_uq');
            });
        }

        if (! Schema::hasTable('crm_quotes')) {
            Schema::create('crm_quotes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id');
                $table->string('number', 24);
                $table->string('status', 20)->default('draft');
                $table->string('property_address', 255)->nullable();
                $table->decimal('subtotal', 10, 2)->default(0);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('total', 10, 2)->default(0);
                $table->text('notes')->nullable();
                $table->date('valid_until')->nullable();
                $table->string('public_token', 64);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('viewed_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->string('decline_reason', 255)->nullable();
                $table->unsignedBigInteger('follow_up_event_id')->nullable();
                $table->string('booking_reference', 120)->nullable();
                $table->boolean('via_robbie')->default(false);
                $table->timestamps();
                $table->unique('number', 'crm_quotes_number_uq');
                $table->unique('public_token', 'crm_quotes_token_uq');
                $table->index(['contact_id', 'status'], 'crm_quotes_contact_idx');
                $table->index('status', 'crm_quotes_status_idx');
            });
        }

        if (! Schema::hasTable('crm_quote_items')) {
            Schema::create('crm_quote_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('quote_id');
                $table->unsignedBigInteger('service_id')->nullable();
                $table->string('name', 190);
                $table->unsignedSmallInteger('quantity')->default(1);
                $table->decimal('unit_price', 10, 2);
                $table->decimal('total', 10, 2);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
                $table->index('quote_id', 'crm_quote_items_quote_idx');
            });
        }

        if (! Schema::hasTable('crm_ai_proposals')) {
            Schema::create('crm_ai_proposals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->string('kind', 24);
                $table->string('summary', 255);
                $table->json('payload');
                $table->string('status', 16)->default('pending');
                $table->json('result')->nullable();
                $table->string('error', 255)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status'], 'crm_ai_proposals_user_idx');
            });
        }

        Schema::table('crm_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_contacts', 'sms_consent_at')) {
                $table->timestamp('sms_consent_at')->nullable();
                $table->string('sms_consent_source', 40)->nullable();
            }
            if (! Schema::hasColumn('crm_contacts', 'health_changed_at')) {
                $table->timestamp('health_changed_at')->nullable();
                $table->date('last_shoot_on')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            foreach (['sms_consent_at', 'sms_consent_source', 'health_changed_at', 'last_shoot_on'] as $column) {
                if (Schema::hasColumn('crm_contacts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        foreach (['crm_ai_proposals', 'crm_quote_items', 'crm_quotes', 'crm_goals', 'crm_companies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
