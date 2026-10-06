<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Services\ExternalBooking\ExternalBookingSubmission;
use App\Services\Shoots\ShootMutationSupportService;
use App\Support\LockedWrite;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Recover a verified pending legacy request without approving it or sending another booking notice. */
class ImportLegacyShootRequest extends Command
{
    protected $signature = 'import:legacy-shoot-request {path : Verified request JSON} {--apply : Import instead of previewing}';
    protected $description = 'Preview or recover a pending legacy shoot request with duplicate protection';

    public function handle(): int
    {
        $input = json_decode(file_get_contents($this->argument('path')), true, 512, JSON_THROW_ON_ERROR);
        $data = Validator::make($input, [
            'company_id' => 'required|integer|min:1', 'request_id' => 'required|integer|min:1',
            'client_id' => 'required|exists:users,id', 'rep_id' => 'required|exists:users,id',
            'photographer_id' => 'required|exists:users,id', 'service_id' => 'required|exists:services,id',
            'address' => 'required|string|max:255', 'city' => 'required|string|max:255',
            'state' => 'required|string|size:2', 'zip' => 'required|string|max:10',
            'preferred_date' => 'required|date_format:Y-m-d', 'preferred_time' => 'required|date_format:H:i',
            'timezone' => 'required|timezone', 'base_quote' => 'required|numeric|min:0',
            'tax_amount' => 'required|numeric|min:0', 'total_quote' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:5000', 'property_details' => 'nullable|array',
        ])->validate();
        if (abs($data['base_quote'] + $data['tax_amount'] - $data['total_quote']) > 0.01) {
            $this->error('Legacy quote does not reconcile.');
            return self::FAILURE;
        }
        $data['source'] = 'viewshoot_legacy_request';
        $data['external_reference'] = $data['company_id'].':'.$data['request_id'];
        if (! $this->option('apply')) {
            $this->info('Preview: '.$data['address'].'; status requested; total '.$data['total_quote'].'; no writes.');
            return self::SUCCESS;
        }
        $result = LockedWrite::run(fn () => DB::transaction(function () use ($data) {
            $submission = app(ExternalBookingSubmission::class);
            if ($existing = $submission->claim($data)) {
                return $existing;
            }
            $at = Carbon::createFromFormat('Y-m-d H:i', $data['preferred_date'].' '.$data['preferred_time'], $data['timezone'])->utc();
            $shoot = Shoot::withoutEvents(fn () => Shoot::create([
                ...collect($data)->only(['client_id', 'rep_id', 'photographer_id', 'service_id', 'address', 'city', 'state', 'zip', 'base_quote', 'tax_amount', 'total_quote', 'property_details'])->all(),
                'status' => Shoot::STATUS_REQUESTED, 'workflow_status' => Shoot::STATUS_REQUESTED,
                'scheduled_date' => $data['preferred_date'], 'time' => $data['preferred_time'],
                'scheduled_at' => $at, 'timezone' => $data['timezone'], 'payment_status' => 'unpaid',
                'tax_percent' => $data['base_quote'] > 0 ? round($data['tax_amount'] / $data['base_quote'] * 100, 4) : 0,
                'tax_region' => $data['state'], 'shoot_notes' => $data['notes'] ?? null,
                'created_by' => 'Legacy request recovery', 'updated_by' => 'Legacy request recovery',
                'external_booking_payload' => [...$data, 'legacy_migration' => ['notifications_suppressed' => true]],
                'external_booking_mapping_status' => 'auto_mapped',
            ]));
            app(ShootMutationSupportService::class)->attachServices($shoot, [[
                'id' => $data['service_id'], 'quantity' => 1, 'price' => $data['base_quote'],
                'photographer_id' => $data['photographer_id'], 'scheduled_at' => $at->format('Y-m-d H:i:s'),
            ]]);
            $response = ['shoot_id' => $shoot->id, 'status' => 'requested', 'total_quote' => $shoot->total_quote];
            $submission->complete($data, $response);
            return $response;
        }), 'recover-legacy-request');
        $this->info(json_encode($result, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
