<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('iguide_lab_tour_budgets',function(Blueprint $table){
            $table->uuid('id')->primary();$table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->unsignedInteger('max_items');$table->unsignedInteger('reserved_items')->default(0);
            $table->json('limits');$table->json('reserved');$table->string('authority_sha256',64);
            $table->timestamp('expires_at');$table->timestamp('revoked_at')->nullable();$table->timestamps();
            $table->index(['project_id','expires_at']);
        });
    }
    public function down():void {Schema::dropIfExists('iguide_lab_tour_budgets');}
};
