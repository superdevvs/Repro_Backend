<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQLite, we need to recreate the table since it doesn't support ALTER COLUMN with unique constraints
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=off;');
            
            // Create new table with nullable square_payment_id
            DB::statement('
                CREATE TABLE payments_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    shoot_id INTEGER NOT NULL,
                    invoice_id INTEGER,
                    amount DECIMAL(10, 2) NOT NULL,
                    currency VARCHAR(3) NOT NULL DEFAULT \'USD\',
                    square_payment_id VARCHAR(255),
                    square_order_id VARCHAR(255),
                    status VARCHAR(255) NOT NULL,
                    processed_at TIMESTAMP,
                    created_at TIMESTAMP,
                    updated_at TIMESTAMP,
                    FOREIGN KEY (shoot_id) REFERENCES shoots(id) ON DELETE CASCADE,
                    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
                )
            ');
            
            // Copy all data
            DB::statement('INSERT INTO payments_new SELECT * FROM payments;');
            
            // Drop old table
            DB::statement('DROP TABLE payments;');
            
            // Rename new table
            DB::statement('ALTER TABLE payments_new RENAME TO payments;');
            
            // Create unique index (SQLite allows multiple NULLs in unique indexes)
            DB::statement('CREATE UNIQUE INDEX payments_square_payment_id_unique ON payments(square_payment_id);');
            
            DB::statement('PRAGMA foreign_keys=on;');
        } else {
            // For MySQL, PostgreSQL, etc.
            Schema::table('payments', function (Blueprint $table) {
                $table->dropUnique(['square_payment_id']);
            });
            
            Schema::table('payments', function (Blueprint $table) {
                $table->string('square_payment_id')->nullable()->change();
            });
            
            // Re-add unique constraint (allows multiple NULLs)
            Schema::table('payments', function (Blueprint $table) {
                $table->unique('square_payment_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This will fail if there are any NULL values in square_payment_id
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=off;');
            
            DB::statement('
                CREATE TABLE payments_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    shoot_id INTEGER NOT NULL,
                    invoice_id INTEGER,
                    amount DECIMAL(10, 2) NOT NULL,
                    currency VARCHAR(3) NOT NULL DEFAULT \'USD\',
                    square_payment_id VARCHAR(255) NOT NULL,
                    square_order_id VARCHAR(255),
                    status VARCHAR(255) NOT NULL,
                    processed_at TIMESTAMP,
                    created_at TIMESTAMP,
                    updated_at TIMESTAMP,
                    FOREIGN KEY (shoot_id) REFERENCES shoots(id) ON DELETE CASCADE,
                    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
                    UNIQUE(square_payment_id)
                )
            ');
            
            // Only copy rows with non-null square_payment_id
            DB::statement('INSERT INTO payments_new SELECT * FROM payments WHERE square_payment_id IS NOT NULL;');
            
            DB::statement('DROP TABLE payments;');
            DB::statement('ALTER TABLE payments_new RENAME TO payments;');
            
            DB::statement('PRAGMA foreign_keys=on;');
        } else {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropUnique(['square_payment_id']);
            });
            
            Schema::table('payments', function (Blueprint $table) {
                $table->string('square_payment_id')->nullable(false)->change();
            });
            
            Schema::table('payments', function (Blueprint $table) {
                $table->unique('square_payment_id');
            });
        }
    }
};
