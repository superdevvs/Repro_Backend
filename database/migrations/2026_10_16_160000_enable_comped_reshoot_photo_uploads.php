<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This live catalogue identity was omitted from both intake backfills.
        // Enable photo capture without changing pricing, pay, or an unknown count.
        $service = DB::table('services')->join('categories', 'categories.id', '=', 'services.category_id')
            ->where('services.id', 88)->where('services.name', 'Comped Reshoot')
            ->where('categories.name', 'Reshoot Comp')->where('services.upload_intake_type', 'none')
            ->exists();

        if ($service) {
            DB::table('services')->where('id', 88)->where('upload_intake_type', 'none')->update([
                'upload_intake_type' => 'photo',
                'uses_hdr_brackets' => true,
            ]);
        }
    }

    public function down(): void
    {
        // Preserve intake once real uploads may reference this service.
    }
};
