<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_channels', function (Blueprint $table) {
            if (!Schema::hasColumn('message_channels', 'display_name')) {
                $table->string('display_name')->nullable()->after('provider');
            }

            if (!Schema::hasColumn('message_channels', 'owner_scope')) {
                $table->string('owner_scope')->default('GLOBAL')->after('is_default');
            }
        });

        if (Schema::hasColumn('message_channels', 'label')) {
            DB::table('message_channels')
                ->whereNull('display_name')
                ->update(['display_name' => DB::raw('label')]);
        }

        if (Schema::hasColumn('message_channels', 'scope')) {
            DB::table('message_channels')
                ->whereNull('owner_scope')
                ->update(['owner_scope' => DB::raw('scope')]);
        }
    }

    public function down(): void
    {
        Schema::table('message_channels', function (Blueprint $table) {
            if (Schema::hasColumn('message_channels', 'display_name')) {
                $table->dropColumn('display_name');
            }

            if (Schema::hasColumn('message_channels', 'owner_scope')) {
                $table->dropColumn('owner_scope');
            }
        });
    }
};





