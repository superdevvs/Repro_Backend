<?php

use App\Services\Shoots\ReviewedTravelFeeCatalog;
use App\Support\LockedWrite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        LockedWrite::run(fn () => DB::transaction(function (): void {
            $products = DB::table('services')->whereIn('id', array_keys(ReviewedTravelFeeCatalog::PRODUCTS))->get();
            foreach ($products as $product) ReviewedTravelFeeCatalog::assertIdentity($product);
            // Existing bookings, compensation and all delivery history require a
            // separately reviewed repair; this only changes future booking facts.
            DB::table('services')->whereIn('id', $products->pluck('id'))->update(['financial_only' => true]);
        }), 'classify-reviewed-travel-catalog');
    }

    public function down(): void
    {
        // Retain reviewed business classification when application code rolls back.
    }
};
