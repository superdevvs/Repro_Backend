<?php

namespace App\Services\ExternalBooking;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The reservation and shoot must be written in the same transaction. */
final class ExternalBookingSubmission
{
    public function claim(array $input): ?array
    {
        if (! isset($input['external_reference']) || $input['external_reference'] === '') {
            return null;
        }

        $identity = $this->identity($input);
        $canonical = $input;
        $canonical['source'] = $identity['source'];
        $canonical['create_account'] = (bool) ($input['create_account'] ?? true);
        $hash = hash('sha256', json_encode($this->canonicalize($canonical), JSON_THROW_ON_ERROR));

        // Insert before reading: SQLite obtains its write lock before taking a
        // read snapshot; the unique key serializes concurrent retries elsewhere.
        $inserted = DB::table('external_booking_submissions')->insertOrIgnore([
            ...$identity,
            'request_hash' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted) {
            return null;
        }

        $existing = DB::table('external_booking_submissions')->where($identity)->lockForUpdate()->first();
        if (! $existing || ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages([
                'external_reference' => ['This booking reference has already been used for a different request.'],
            ])->status(409);
        }

        if (! $existing->response_payload) {
            throw ValidationException::withMessages([
                'external_reference' => ['This booking is already being processed. Retry the same request later.'],
            ])->status(409);
        }

        return json_decode($existing->response_payload, true, 512, JSON_THROW_ON_ERROR);
    }

    public function complete(array $input, array $response): void
    {
        if (! isset($input['external_reference']) || $input['external_reference'] === '') {
            return;
        }

        DB::table('external_booking_submissions')->where($this->identity($input))->update([
            'shoot_id' => $response['shoot_id'],
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function identity(array $input): array
    {
        return [
            'source' => $input['source'] ?? 'external_website',
            'external_reference' => $input['external_reference'],
        ];
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
