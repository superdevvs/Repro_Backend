<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_group_id')->constrained('sms_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('phone');
            $table->timestamps();

            $table->unique(['sms_group_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_group_members');
        Schema::dropIfExists('sms_groups');
    }
};
