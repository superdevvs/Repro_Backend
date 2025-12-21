<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->index('status', 'shoots_status_index');
            $table->index('workflow_status', 'shoots_workflow_status_index');
            $table->index('scheduled_date', 'shoots_scheduled_date_index');
            $table->index('client_id', 'shoots_client_id_index');
            $table->index('photographer_id', 'shoots_photographer_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropIndex('shoots_status_index');
            $table->dropIndex('shoots_workflow_status_index');
            $table->dropIndex('shoots_scheduled_date_index');
            $table->dropIndex('shoots_client_id_index');
            $table->dropIndex('shoots_photographer_id_index');
        });
    }
};
