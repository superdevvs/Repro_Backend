<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_numbers', function (Blueprint $table) {
            if (!Schema::hasColumn('sms_numbers', 'provider')) {
                $table->string('provider')->default('TWILIO')->after('id');
            }

            if (!Schema::hasColumn('sms_numbers', 'twilio_phone_number_sid')) {
                $table->string('twilio_phone_number_sid')->nullable()->after('label');
            }
        });

        DB::table('sms_numbers')->update([
            'provider' => 'TWILIO',
            'twilio_phone_number_sid' => config('services.twilio.phone_number_sid'),
        ]);

        if (Schema::hasColumn('sms_numbers', 'mighty_call_key')) {
            Schema::table('sms_numbers', function (Blueprint $table) {
                $table->dropColumn('mighty_call_key');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sms_numbers', 'mighty_call_key')) {
            Schema::table('sms_numbers', function (Blueprint $table) {
                $table->string('mighty_call_key')->nullable()->after('phone_number');
            });
        }

        Schema::table('sms_numbers', function (Blueprint $table) {
            if (Schema::hasColumn('sms_numbers', 'provider')) {
                $table->dropColumn('provider');
            }

            if (Schema::hasColumn('sms_numbers', 'twilio_phone_number_sid')) {
                $table->dropColumn('twilio_phone_number_sid');
            }
        });
    }
};
