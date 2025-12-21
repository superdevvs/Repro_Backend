<?php

// database/migrations/xxxx_xx_xx_create_shoots_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShootsTable extends Migration
{
    public function up()
    {
        Schema::create('shoots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('photographer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');

            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('zip');

            $table->date('scheduled_date')->nullable();
            $table->string('time')->nullable(); // You can use `time` type if always in HH:MM

            $table->decimal('base_quote', 10, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->decimal('total_quote', 10, 2);
            $table->string('payment_status')->default('unpaid'); // 'paid', 'unpaid', 'partial'
            $table->string('payment_type')->nullable(); // 'credit_card', etc.

            $table->text('notes')->nullable();
            $table->string('status')->default('booked'); // 'booked', 'cancelled', etc.

            $table->string('created_by');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('shoots');
    }
}
