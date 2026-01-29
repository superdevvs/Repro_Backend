<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mmm_punchout_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shoot_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('buyer_cookie')->nullable();
            $table->string('cost_center_number')->nullable();
            $table->string('employee_email')->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('template_external_number')->nullable();
            $table->string('order_number')->nullable();
            $table->string('redirect_url')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('redirected_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->index(['shoot_id', 'status']);
            $table->index(['buyer_cookie']);

            $table->foreign('shoot_id')->references('id')->on('shoots')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mmm_punchout_sessions');
    }
};
