<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('watermark_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('default');
            $table->boolean('is_active')->default(true);
            
            // Logo settings
            $table->boolean('logo_enabled')->default(true);
            $table->string('logo_position')->default('bottom-right'); // top-left, top-right, bottom-left, bottom-right, custom
            $table->integer('logo_opacity')->default(60); // 0-100
            $table->decimal('logo_size', 5, 2)->default(20); // percentage of image width
            $table->integer('logo_offset_x')->default(3); // percentage from edge
            $table->integer('logo_offset_y')->default(8); // percentage from edge
            $table->string('custom_logo_url')->nullable(); // custom logo override
            
            // Text watermark settings
            $table->boolean('text_enabled')->default(false);
            $table->string('text_content')->nullable();
            $table->string('text_style')->default('diagonal'); // diagonal, repeated, corner, banner
            $table->integer('text_opacity')->default(40); // 0-100
            $table->string('text_color')->default('#FFFFFF');
            $table->integer('text_size')->default(10); // percentage of image width
            $table->integer('text_spacing')->default(200); // pixels between repeated text
            
            // Overlay settings
            $table->boolean('overlay_enabled')->default(false);
            $table->string('overlay_color')->default('rgba(0,0,0,0.1)');
            
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Insert default settings
        DB::table('watermark_settings')->insert([
            'name' => 'default',
            'is_active' => true,
            'logo_enabled' => true,
            'logo_position' => 'bottom-right',
            'logo_opacity' => 60,
            'logo_size' => 20,
            'logo_offset_x' => 3,
            'logo_offset_y' => 8,
            'text_enabled' => false,
            'text_style' => 'diagonal',
            'text_opacity' => 40,
            'text_color' => '#FFFFFF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watermark_settings');
    }
};
