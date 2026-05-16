<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_numbers', function (Blueprint $table): void {
            if (!Schema::hasColumn('sms_numbers', 'telnyx_phone_number_id')) {
                $table->string('telnyx_phone_number_id')->nullable()->after('twilio_phone_number_sid');
            }

            if (!Schema::hasColumn('sms_numbers', 'messaging_profile_id')) {
                $table->string('messaging_profile_id')->nullable()->after('telnyx_phone_number_id');
            }
        });

        DB::table('sms_numbers')
            ->where('provider', 'TWILIO')
            ->update(['provider' => 'TELNYX']);
    }

    public function down(): void
    {
        DB::table('sms_numbers')
            ->where('provider', 'TELNYX')
            ->update(['provider' => 'TWILIO']);

        Schema::table('sms_numbers', function (Blueprint $table): void {
            if (Schema::hasColumn('sms_numbers', 'messaging_profile_id')) {
                $table->dropColumn('messaging_profile_id');
            }

            if (Schema::hasColumn('sms_numbers', 'telnyx_phone_number_id')) {
                $table->dropColumn('telnyx_phone_number_id');
            }
        });
    }
};
