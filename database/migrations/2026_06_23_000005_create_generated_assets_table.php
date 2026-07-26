<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('instruction_index');
            $table->text('instruction_text');
            $table->string('asset_path')->nullable();
            $table->string('placement');
            $table->text('alt_text')->nullable();
            $table->enum('status', ['produced', 'failed']);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['team_id', 'instruction_index']);
            $table->index(['team_id', 'placement']);
            $table->index(['team_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_assets');
    }
};
