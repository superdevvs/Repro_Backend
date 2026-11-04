<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical SQLite table rebuilds removed these nullable columns while
        // retaining the original migration ledger. Restore the existing model
        // contract without changing any historical contact or message values.
        foreach (['phones_json', 'tags_json', 'comment'] as $column) {
            if (! Schema::hasColumn('contacts', $column)) {
                Schema::table('contacts', function (Blueprint $table) use ($column): void {
                    $column === 'comment' ? $table->text($column)->nullable() : $table->json($column)->nullable();
                });
            }
        }

        $userId = collect(Schema::getColumns('users'))->firstWhere('name', 'id');
        foreach (['assigned_to_user_id', 'status', 'tags_json'] as $column) {
            if (! Schema::hasColumn('message_threads', $column)) {
                Schema::table('message_threads', function (Blueprint $table) use ($column, $userId): void {
                    if ($column === 'assigned_to_user_id') {
                        // The converted SQLite IDs are signed BIGINTs. A fresh
                        // Laravel MySQL database can instead have unsigned IDs.
                        $table->bigInteger($column, false, str_contains($userId['type'] ?? '', 'unsigned'))->nullable();
                        $table->foreign($column)->references('id')->on('users')->nullOnDelete();
                    } elseif ($column === 'tags_json') {
                        $table->json($column)->nullable();
                    } else {
                        $table->string($column)->nullable();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // These columns belong to the pre-existing messaging contract. Keep
        // user data and any columns that already existed before this repair.
    }
};
