<?php

use App\Services\Messaging\MessagingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreignId('related_shoot_id')->nullable()->after('contact_id');
            $table->index(
                ['channel', 'contact_id', 'related_shoot_id'],
                'message_threads_channel_contact_shoot_index'
            );
        });

        app(MessagingService::class)->backfillLinkedInternalContactThreadsByShoot();
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropIndex('message_threads_channel_contact_shoot_index');
            $table->dropColumn('related_shoot_id');
        });
    }
};
