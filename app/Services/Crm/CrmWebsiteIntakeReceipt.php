<?php

namespace App\Services\Crm;

use App\Models\Shoot;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class CrmWebsiteIntakeReceipt
{
    public function register(string $kind, string $reference, array $payload): void
    {
        if (! config('crm.enabled')) {
            return;
        }

        LockedWrite::run(fn () => DB::table('crm_website_intake_receipts')->insertOrIgnore([
            'kind' => $kind,
            'reference' => strtolower($reference),
            'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'created_at' => now(),
            'updated_at' => now(),
        ]), 'crm.website.register');
    }

    public function process(string $kind, string $reference): void
    {
        if (! config('crm.enabled')) {
            return;
        }

        try {
            LockedWrite::run(fn () => DB::transaction(function () use ($kind, $reference) {
                $receipt = DB::table('crm_website_intake_receipts')
                    ->where('kind', $kind)->where('reference', strtolower($reference))
                    ->whereNull('completed_at')->lockForUpdate()->first();
                if (! $receipt) {
                    return;
                }

                $payload = json_decode(Crypt::decryptString($receipt->payload), true, flags: JSON_THROW_ON_ERROR);
                $intake = app(CrmWebsiteIntake::class);
                if ($kind === 'inquiry') {
                    $intake->inquiry($payload, $receipt->reference);
                } else {
                    $shoot = Shoot::find($payload['shoot_id']);
                    if ($shoot) {
                        $intake->booking($shoot, $receipt->reference, $payload['notes'] ?? null);
                    }
                }

                DB::table('crm_website_intake_receipts')->where('id', $receipt->id)
                    ->update(['completed_at' => now(), 'updated_at' => now(), 'payload' => null]);
            }), 'crm.website.process');
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function retryPending(): void
    {
        if (! config('crm.enabled')) {
            return;
        }

        DB::table('crm_website_intake_receipts')->whereNull('completed_at')->orderBy('id')->chunkById(100, function ($receipts) {
            foreach ($receipts as $receipt) {
                $this->process($receipt->kind, $receipt->reference);
            }
        });
    }
}
