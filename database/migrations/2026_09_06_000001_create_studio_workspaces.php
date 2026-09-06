<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('request_id', 64)->nullable();
            $table->string('name');
            $table->string('preset_id', 64);
            $table->json('media');
            $table->json('config');
            $table->json('outputs')->nullable();
            $table->json('prepared_frames')->nullable();
            $table->json('operation')->nullable();
            $table->json('history')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedTinyInteger('progress')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['created_by', 'request_id']);
        });
        // One-time rollout grant; subsequent administrator permission changes remain authoritative.
        if (Schema::hasTable('settings')) {
            $setting = DB::table('settings')->where('key', 'permissions.role_map.v1')->first();
            $value = $setting ? json_decode($setting->value, true) : null;
            if (is_array($value)) {
                $roles = $value['roles'] ?? $value;
                foreach (['client', 'editor'] as $role) {
                    if (isset($roles[$role]) && is_array($roles[$role])) {
                        $roles[$role] = array_values(array_unique([...$roles[$role], 'ai-editing-view']));
                    }
                }
                if (isset($value['roles'])) {
                    $value['roles'] = $roles;
                } else {
                    $value = $roles;
                }
                DB::table('settings')->where('key', 'permissions.role_map.v1')->update(['value' => json_encode($value)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_workspaces');
    }
};
