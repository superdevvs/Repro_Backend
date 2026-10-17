<?php

use App\Support\LockedWrite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $main = $this->normalize((string) config('services.telnyx.from_number'));
        if (! $main) {
            return;
        }
        LockedWrite::run(fn () => DB::transaction(function () use ($main): void {
            // Empty installations already register their configured line in SMS
            // Settings. Repair existing registries without changing a chosen default.
            if (! DB::table('sms_numbers')->exists() || DB::table('sms_numbers')->where('is_default', true)->exists()) {
                return;
            }
            $existing = DB::table('sms_numbers')->where('provider', 'TELNYX')->orderBy('id')->get()
                ->first(fn ($number) => $this->normalize((string) $number->phone_number) === $main);
            $configuration = [
                'telnyx_phone_number_id' => trim((string) config('services.telnyx.phone_number_id')) ?: null,
                'messaging_profile_id' => trim((string) config('services.telnyx.messaging_profile_id')) ?: null,
            ];
            if ($existing) {
                $updates = ['is_default' => true, 'updated_at' => now()];
                foreach ($configuration as $field => $value) {
                    if (empty($existing->{$field}) && $value !== null) {
                        $updates[$field] = $value;
                    }
                }
                DB::table('sms_numbers')->where('id', $existing->id)->update($updates);

                return;
            }
            DB::table('sms_numbers')->insert(array_merge($configuration, [
                'provider' => 'TELNYX', 'phone_number' => $main,
                'label' => config('services.telnyx.default_label') ?: 'Main SMS',
                'owner_type' => 'GLOBAL', 'is_default' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }), 'sms.register-configured-main');
    }

    public function down(): void
    {
        // Keep operating lines and any conversations created with this sender.
    }

    private function normalize(string $phone): ?string
    {
        $phone = trim($phone);
        if (! preg_match('/^[+0-9 ().-]+$/D', $phone)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        $digits = strlen($digits) === 10 ? '1'.$digits : $digits;

        return preg_match('/^[1-9][0-9]{7,14}$/D', $digits) ? '+'.$digits : null;
    }
};
