<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            // MLS Integration
            $table->string('mls_id')->nullable()->after('zip');
            $table->string('listing_source')->nullable()->after('mls_id'); // 'BrightMLS', 'Other'
            
            // Zillow/Bridge Integration
            $table->json('property_details')->nullable()->after('listing_source'); // Store beds, baths, sqft, etc. from Zillow
            
            // Integration flags
            $table->json('integration_flags')->nullable()->after('property_details'); // Store feature flags like canPublishToBright
            
            // Bright MLS Integration
            $table->string('bright_mls_publish_status')->nullable()->after('integration_flags'); // 'pending', 'published', 'error'
            $table->timestamp('bright_mls_last_published_at')->nullable()->after('bright_mls_publish_status');
            $table->text('bright_mls_response')->nullable()->after('bright_mls_last_published_at'); // Store API response/error
            $table->string('bright_mls_manifest_id')->nullable()->after('bright_mls_response');
            
            // iGUIDE Integration
            $table->string('iguide_tour_url')->nullable()->after('bright_mls_manifest_id');
            $table->json('iguide_floorplans')->nullable()->after('iguide_tour_url'); // Array of floorplan URLs/PDFs
            $table->timestamp('iguide_last_synced_at')->nullable()->after('iguide_floorplans');
            $table->string('iguide_property_id')->nullable()->after('iguide_last_synced_at');
            
            // Private listing flag
            $table->boolean('is_private_listing')->default(false)->after('iguide_property_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn([
                'mls_id',
                'listing_source',
                'property_details',
                'integration_flags',
                'bright_mls_publish_status',
                'bright_mls_last_published_at',
                'bright_mls_response',
                'bright_mls_manifest_id',
                'iguide_tour_url',
                'iguide_floorplans',
                'iguide_last_synced_at',
                'iguide_property_id',
                'is_private_listing',
            ]);
        });
    }
};


