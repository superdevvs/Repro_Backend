<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('iguide_offline_upload_sessions', function (Blueprint $table) {
            $table->foreignId('shoot_service_id')->nullable()->constrained('shoot_service')->restrictOnDelete();
        });
        Schema::table('shoot_units', function (Blueprint $table) {
            $table->json('tour_links')->nullable();
            $table->json('property_details')->nullable();
            $table->json('provider_data')->nullable();
            $table->boolean('include_common_area_media')->default(false);
            $table->string('property_status')->nullable();
            $table->string('listing_type')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('iguide_offline_upload_sessions', fn (Blueprint $table) => $table->dropConstrainedForeignId('shoot_service_id'));
        Schema::table('shoot_units', function (Blueprint $table) {
            $table->dropColumn(['tour_links', 'property_details', 'provider_data', 'include_common_area_media', 'property_status', 'listing_type']);
        });
    }
};
