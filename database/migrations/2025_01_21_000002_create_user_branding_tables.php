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
        // User branding settings table
        Schema::create('user_branding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('logo')->nullable();
            $table->string('primary_color')->default('#1a56db');
            $table->string('secondary_color')->default('#7e3af2');
            $table->string('font_family')->default('Inter');
            $table->string('custom_domain')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        // User-branding client associations
        Schema::create('user_branding_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('client_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index('user_id');
            $table->index('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_branding_clients');
        Schema::dropIfExists('user_branding');
    }
};


