<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not automatically index foreign keys. Search checks these
        // relations for every invoice/thread, so a missing lookup index turns
        // one search into thousands of full table scans.
        if (! Schema::hasIndex('invoice_items', 'invoice_items_invoice_lookup')) {
            Schema::table('invoice_items', fn (Blueprint $table) => $table->index('invoice_id', 'invoice_items_invoice_lookup'));
        }
        if (! Schema::hasIndex('messages', 'messages_thread_visibility_lookup')) {
            Schema::table('messages', fn (Blueprint $table) => $table->index(['thread_id', 'channel', 'hidden_from_inbox'], 'messages_thread_visibility_lookup'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('messages', 'messages_thread_visibility_lookup')) {
            Schema::table('messages', fn (Blueprint $table) => $table->dropIndex('messages_thread_visibility_lookup'));
        }
        if (Schema::hasIndex('invoice_items', 'invoice_items_invoice_lookup')) {
            Schema::table('invoice_items', fn (Blueprint $table) => $table->dropIndex('invoice_items_invoice_lookup'));
        }
    }
};
