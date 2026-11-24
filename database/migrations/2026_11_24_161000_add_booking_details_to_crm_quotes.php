<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotes', function (Blueprint $table) {
            $table->string('property_city')->nullable();
            $table->string('property_state', 2)->nullable();
            $table->string('property_zip', 10)->nullable();
            $table->unsignedInteger('sqft')->nullable();
            $table->string('booking_status', 20)->nullable();
            $table->unsignedBigInteger('booking_shoot_id')->nullable()->unique('crm_quote_booking_shoot_uq');
            $table->string('booking_review_reason')->nullable();
        });
        DB::table('crm_quotes')->where('status', 'accepted')->update([
            'booking_status' => 'needs_review',
            'booking_review_reason' => 'Check for an existing booking before creating a request for this accepted quote.',
        ]);
    }

    public function down(): void
    {
        Schema::table('crm_quotes', function (Blueprint $table) {
            $table->dropUnique('crm_quote_booking_shoot_uq');
            $table->dropColumn(['property_city', 'property_state', 'property_zip', 'sqft', 'booking_status', 'booking_shoot_id', 'booking_review_reason']);
        });
    }
};
