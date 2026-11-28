<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editor_payouts', function (Blueprint $table) {
            // Historical execution identity deliberately survives the booked row.
            $table->unsignedBigInteger('removed_shoot_service_id')->nullable()->index();
            $table->timestamp('service_removed_at')->nullable();
        });
        $this->replaceExecutionIndex('COALESCE(shoot_service_id, removed_shoot_service_id, 0)');
    }

    public function down(): void
    {
        if (DB::table('editor_payouts')->whereNotNull('removed_shoot_service_id')->exists()) {
            throw new RuntimeException('Removed service editor payouts exist; refusing to discard their execution identity.');
        }
        $this->replaceExecutionIndex('COALESCE(shoot_service_id, 0)');
        Schema::table('editor_payouts', fn (Blueprint $table) => $table->dropColumn(['removed_shoot_service_id', 'service_removed_at']));
    }

    private function replaceExecutionIndex(string $expression): void
    {
        Schema::table('editor_payouts', fn (Blueprint $table) => $table->dropUnique('editor_payouts_execution_unique'));
        // MySQL functional key parts require their own parentheses; SQLite
        // accepts the expression directly. Preserve the same NULL identity.
        if (DB::getDriverName() === 'mysql') {
            $expression = '('.$expression.')';
        }
        $table = DB::connection()->getQueryGrammar()->wrapTable('editor_payouts');
        DB::statement('CREATE UNIQUE INDEX editor_payouts_execution_unique ON '.$table.' (editor_id, shoot_id, service_id, '.$expression.')');
    }
};
