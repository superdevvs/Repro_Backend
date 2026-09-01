<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->foreignId('reshoot_of_shoot_id')
                ->nullable()
                ->after('shoot_type')
                ->constrained('shoots')
                ->restrictOnDelete();
            $table->foreignId('root_shoot_id')
                ->nullable()
                ->after('reshoot_of_shoot_id')
                ->constrained('shoots')
                ->restrictOnDelete();
            $table->string('complimentary_reshoot_idempotency_key', 64)
                ->nullable()
                ->after('root_shoot_id')
                ->unique('shoots_comp_reshoot_idempotency_unique');
            $table->char('complimentary_reshoot_request_hash', 64)
                ->nullable()
                ->after('complimentary_reshoot_idempotency_key');

            $table->index(
                ['root_shoot_id', 'reshoot_of_shoot_id'],
                'shoots_comp_reshoot_lineage_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropIndex('shoots_comp_reshoot_lineage_index');
            $table->dropUnique('shoots_comp_reshoot_idempotency_unique');
            $table->dropForeign(['root_shoot_id']);
            $table->dropForeign(['reshoot_of_shoot_id']);
            $table->dropColumn([
                'complimentary_reshoot_request_hash',
                'complimentary_reshoot_idempotency_key',
                'root_shoot_id',
                'reshoot_of_shoot_id',
            ]);
        });
    }
};
