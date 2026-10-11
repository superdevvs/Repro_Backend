<?php

use App\Models\ShootService;
use App\Services\Invoices\PhotographerPayoutBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shoot_service', fn (Blueprint $table) => $table->json('payout_snapshot')->nullable());
        // Freeze existing booking data without recalculating any invoice/payment.
        ShootService::with(['shoot', 'service'])->whereNull('payout_snapshot')->chunkById(100, function ($lines) {
            foreach ($lines as $line) {
                if (! $line->shoot || ! $line->service || $line->shoot->isComplimentaryReshoot()) continue;
                app(PhotographerPayoutBasis::class)->capture($line, $line->shoot, $line->service, true);
                $line->timestamps = false;
                $line->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoot_service', fn (Blueprint $table) => $table->dropColumn('payout_snapshot'));
    }
};
