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
        // SQLite doesn't support ALTER COLUMN directly, so we handle it differently
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            // For SQLite, we can't easily change column nullability
            // We'll just add the new columns and handle nulls in the application code
        } else {
            // For MySQL/PostgreSQL, use change() method
            Schema::table('invoices', function (Blueprint $table) {
                // Make period_start and period_end nullable for shoot-based invoices
                if (Schema::hasColumn('invoices', 'period_start')) {
                    $table->date('period_start')->nullable()->change();
                }
                if (Schema::hasColumn('invoices', 'period_end')) {
                    $table->date('period_end')->nullable()->change();
                }
                
                // Make role nullable for shoot-based invoices
                if (Schema::hasColumn('invoices', 'role')) {
                    $table->string('role')->nullable()->change();
                }
            });
        }
        
        Schema::table('invoices', function (Blueprint $table) {
            // Add shoot_id column for shoot-based invoices
            if (!Schema::hasColumn('invoices', 'shoot_id')) {
                $table->foreignId('shoot_id')->nullable()->after('id')->constrained('shoots')->nullOnDelete();
            }
            
            // Add client_id column for shoot-based invoices
            if (!Schema::hasColumn('invoices', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('shoot_id')->constrained('users')->nullOnDelete();
            }
            
            // Add photographer_id column if not exists (might already exist)
            if (!Schema::hasColumn('invoices', 'photographer_id')) {
                $table->foreignId('photographer_id')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
            }
            
            // Add invoice_number for shoot-based invoices
            if (!Schema::hasColumn('invoices', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->after('photographer_id');
            }
            
            // Add issue_date for shoot-based invoices
            if (!Schema::hasColumn('invoices', 'issue_date')) {
                $table->dateTime('issue_date')->nullable()->after('invoice_number');
            }
            
            // Add due_date for shoot-based invoices
            if (!Schema::hasColumn('invoices', 'due_date')) {
                $table->dateTime('due_date')->nullable()->after('issue_date');
            }
            
            // Add subtotal for shoot-based invoices
            if (!Schema::hasColumn('invoices', 'subtotal')) {
                $table->decimal('subtotal', 10, 2)->nullable()->after('due_date');
            }
            
            // Add tax for shoot-based invoices
            if (!Schema::hasColumn('invoices', 'tax')) {
                $table->decimal('tax', 10, 2)->nullable()->after('subtotal');
            }
            
            // Add total for shoot-based invoices
            if (!Schema::hasColumn('invoices', 'total')) {
                $table->decimal('total', 10, 2)->nullable()->after('tax');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'total')) {
                $table->dropColumn('total');
            }
            if (Schema::hasColumn('invoices', 'tax')) {
                $table->dropColumn('tax');
            }
            if (Schema::hasColumn('invoices', 'subtotal')) {
                $table->dropColumn('subtotal');
            }
            if (Schema::hasColumn('invoices', 'due_date')) {
                $table->dropColumn('due_date');
            }
            if (Schema::hasColumn('invoices', 'issue_date')) {
                $table->dropColumn('issue_date');
            }
            if (Schema::hasColumn('invoices', 'invoice_number')) {
                $table->dropColumn('invoice_number');
            }
            if (Schema::hasColumn('invoices', 'photographer_id')) {
                $table->dropConstrainedForeignId('photographer_id');
            }
            if (Schema::hasColumn('invoices', 'client_id')) {
                $table->dropConstrainedForeignId('client_id');
            }
            if (Schema::hasColumn('invoices', 'shoot_id')) {
                $table->dropConstrainedForeignId('shoot_id');
            }
        });
    }
};
