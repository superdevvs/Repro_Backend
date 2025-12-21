<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('icon');
        });

        // Ensure default Photo and Video categories exist
        $this->ensureDefaultCategories();
    }

    /**
     * Ensure Photo and Video categories exist and are marked as default
     */
    private function ensureDefaultCategories(): void
    {
        $defaults = [
            ['name' => 'Photo', 'icon' => 'camera', 'is_default' => true],
            ['name' => 'Video', 'icon' => 'video', 'is_default' => true],
        ];

        foreach ($defaults as $category) {
            $existing = DB::table('categories')->where('name', $category['name'])->first();
            
            if ($existing) {
                // Mark existing as default
                DB::table('categories')
                    ->where('id', $existing->id)
                    ->update(['is_default' => true]);
            } else {
                // Create new default category
                DB::table('categories')->insert([
                    'name' => $category['name'],
                    'icon' => $category['icon'],
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
