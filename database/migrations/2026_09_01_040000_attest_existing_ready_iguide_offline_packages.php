<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POLICY = 'authorized_staff_official_iguide_export';

    public function up(): void
    {
        if (! Schema::hasTable('shoots')
            || ! Schema::hasColumn('shoots', 'iguide_data')
            || ! Schema::hasTable('shoot_files')
            || ! Schema::hasTable('users')) {
            return;
        }

        DB::table('shoots')
            ->select(['id', 'iguide_data'])
            ->whereNotNull('iguide_data')
            ->orderBy('id')
            ->chunkById(100, function ($shoots): void {
                foreach ($shoots as $shoot) {
                    $data = $this->decode($shoot->iguide_data);
                    $package = $data['manual_offline_package'] ?? null;
                    if (! is_array($package)
                        || ($package['status'] ?? null) !== 'ready'
                        || isset($package['publication_attestation'])
                        || ! is_numeric($package['file_id'] ?? null)
                        || ! is_numeric($package['uploaded_by'] ?? null)) {
                        continue;
                    }

                    $actor = DB::table('users')->where('id', (int) $package['uploaded_by'])->first(['id', 'role']);
                    $role = strtolower(preg_replace('/[_\-\s]+/', '', (string) ($actor->role ?? '')));
                    if (! in_array($role, ['admin', 'superadmin', 'editingmanager'], true)) {
                        continue;
                    }

                    $file = DB::table('shoot_files')
                        ->where('id', (int) $package['file_id'])
                        ->where('shoot_id', (int) $shoot->id)
                        ->where('media_type', 'iguide')
                        ->where('scan_status', 'clean')
                        ->first(['metadata']);
                    $metadata = $this->decode($file?->metadata);
                    $packageUploadId = $package['upload_id'] ?? $package['id'] ?? null;
                    if ($file === null
                        || ! is_string($packageUploadId)
                        || $packageUploadId === ''
                        || ($metadata['kind'] ?? null) !== 'iguide_offline_package'
                        || ($metadata['upload_id'] ?? null) !== $packageUploadId) {
                        continue;
                    }

                    $package['publication_attestation'] = [
                        'policy' => self::POLICY,
                        'version' => 1,
                        'audiences' => ['branded', 'mls'],
                        'attested_by' => (int) $actor->id,
                        'attested_at' => $package['ready_at'] ?? $package['uploaded_at'] ?? now()->toIso8601String(),
                        'backfilled' => true,
                    ];
                    $data['manual_offline_package'] = $package;

                    DB::table('shoots')->where('id', (int) $shoot->id)->update([
                        'iguide_data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shoots') || ! Schema::hasColumn('shoots', 'iguide_data')) {
            return;
        }

        DB::table('shoots')
            ->select(['id', 'iguide_data'])
            ->whereNotNull('iguide_data')
            ->orderBy('id')
            ->chunkById(100, function ($shoots): void {
                foreach ($shoots as $shoot) {
                    $data = $this->decode($shoot->iguide_data);
                    $package = $data['manual_offline_package'] ?? null;
                    $attestation = is_array($package) ? ($package['publication_attestation'] ?? null) : null;
                    if (! is_array($attestation)
                        || ($attestation['policy'] ?? null) !== self::POLICY
                        || ($attestation['backfilled'] ?? false) !== true) {
                        continue;
                    }

                    unset($package['publication_attestation']);
                    $data['manual_offline_package'] = $package;
                    DB::table('shoots')->where('id', (int) $shoot->id)->update([
                        'iguide_data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
