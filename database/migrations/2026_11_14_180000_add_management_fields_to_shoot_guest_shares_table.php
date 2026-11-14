<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_guest_shares', function (Blueprint $table) {
            $table->text('token_encrypted')->nullable();
            $table->string('invitation_status', 16)->nullable();
            $table->timestamp('invitation_attempted_at')->nullable();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->index(['shoot_id', 'email'], 'shoot_guest_shares_recipient_index');
        });
    }

    public function down(): void
    {
        Schema::table('shoot_guest_shares', function (Blueprint $table) {
            $table->dropIndex('shoot_guest_shares_recipient_index');
            $table->dropColumn(['token_encrypted', 'invitation_status', 'invitation_attempted_at', 'invitation_sent_at']);
        });
    }
};
