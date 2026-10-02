<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_route_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('elements');
            $table->timestamp('reserved_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_route_usage');
    }
};
