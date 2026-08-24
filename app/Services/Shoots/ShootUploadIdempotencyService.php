<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootUploadAttempt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ShootUploadIdempotencyService
{
    /**
     * Claim an upload key or return the canonical result already associated with it.
     * Legacy callers without a key remain supported during the additive rollout.
     *
     * @return array{attempt:?ShootUploadAttempt,replay:?array{status:int,payload:array}}
     */
    public function claim(Request $request, Shoot $shoot, ?User $actor, array $files): array
    {
        $key = trim((string) ($request->input('idempotency_key') ?: $request->header('Idempotency-Key', '')));
        if ($key === '' || ! $actor) {
            return ['attempt' => null, 'replay' => null];
        }

        $key = mb_substr($key, 0, 191);
        $fingerprint = $this->fingerprint($request, $files);
        $attributes = [
            'shoot_id' => $shoot->id,
            'actor_id' => $actor->id,
            'idempotency_key' => $key,
        ];

        try {
            $attempt = ShootUploadAttempt::query()->create($attributes + [
                'request_fingerprint' => $fingerprint,
                'upload_type' => strtolower((string) $request->input('upload_type', 'raw')),
                'upload_batch_id' => $this->nullableString($request->input('upload_batch_id')),
                'upload_batch_index' => $this->nullableInteger($request->input('upload_batch_index')),
                'upload_batch_total' => $this->nullableInteger($request->input('upload_batch_total')),
                'shoot_service_id' => $this->nullableInteger($request->input('shoot_service_id')),
                'status' => ShootUploadAttempt::STATUS_PENDING,
                'correlation_id' => (string) Str::uuid(),
            ]);

            return ['attempt' => $attempt, 'replay' => null];
        } catch (QueryException $exception) {
            // A concurrent request may win the unique-key insert. Only translate a
            // real duplicate into replay semantics; let unrelated DB failures surface.
            $attempt = ShootUploadAttempt::query()->where($attributes)->first();
            if (! $attempt) {
                throw $exception;
            }
        }

        if (! hash_equals((string) $attempt->request_fingerprint, $fingerprint)) {
            return [
                'attempt' => null,
                'replay' => [
                    'status' => 409,
                    'payload' => $this->typedError(
                        'idempotency_conflict',
                        'This upload key was already used for different files or upload settings.'
                    ),
                ],
            ];
        }

        if (in_array($attempt->status, [ShootUploadAttempt::STATUS_COMPLETED, ShootUploadAttempt::STATUS_FAILED], true)) {
            return [
                'attempt' => null,
                'replay' => [
                    'status' => (int) ($attempt->http_status ?? 200),
                    'payload' => (array) ($attempt->result_payload ?? $this->typedError(
                        'upload_result_unavailable',
                        'The stored upload result could not be restored.'
                    )),
                ],
            ];
        }

        return [
            'attempt' => null,
            'replay' => [
                'status' => 409,
                'payload' => $this->typedError(
                    'upload_in_progress',
                    'An upload with this key is still being processed.',
                    true
                ) + ['retry_after_seconds' => 2],
            ],
        ];
    }

    public function finish(ShootUploadAttempt $attempt, array $result): void
    {
        $payload = (array) ($result['payload'] ?? []);
        $successCount = (int) ($payload['success_count'] ?? 0);
        $firstFile = collect((array) ($payload['uploaded_files'] ?? []))->first(fn ($file) => is_array($file));
        $attempt->forceFill([
            'status' => $successCount > 0 ? ShootUploadAttempt::STATUS_COMPLETED : ShootUploadAttempt::STATUS_FAILED,
            'http_status' => (int) ($result['status'] ?? 500),
            'result_file_ids' => array_values(array_filter(array_map(
                fn ($file) => is_array($file) ? ($file['id'] ?? null) : null,
                (array) ($payload['uploaded_files'] ?? [])
            ))),
            'result_errors' => array_values((array) ($payload['errors'] ?? [])),
            'result_payload' => $payload,
            'shoot_service_id' => $attempt->shoot_service_id
                ?? (is_array($firstFile) ? ($firstFile['shoot_service_id'] ?? $firstFile['shootServiceId'] ?? null) : null),
            'completed_at' => $successCount > 0 ? now() : null,
            'failed_at' => $successCount > 0 ? null : now(),
        ])->save();
    }

    public function fail(ShootUploadAttempt $attempt, array $payload, int $status = 500): void
    {
        $attempt->forceFill([
            'status' => ShootUploadAttempt::STATUS_FAILED,
            'http_status' => $status,
            'result_file_ids' => [],
            'result_errors' => array_values((array) ($payload['errors'] ?? [])),
            'result_payload' => $payload,
            'failed_at' => now(),
        ])->save();
    }

    public function fingerprint(Request $request, array $files): string
    {
        $fileParts = array_map(function (UploadedFile $file): array {
            $path = $file->getRealPath();

            return [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
                'sha256' => is_string($path) && is_readable($path) ? hash_file('sha256', $path) : null,
            ];
        }, $files);

        // Every field that changes what this upload *is* has to participate. The shoot
        // and actor are already part of the attempt's unique key
        // (shoot_id, actor_id, idempotency_key), so the fingerprint covers the rest:
        // which execution row the files belong to, which lane they arrive through, and
        // the file bytes themselves. `upload_lane` matters because it decides whether
        // bracket stacking applies, so the same bytes submitted as photo and as video are
        // genuinely different uploads.
        $settings = collect($request->only([
            'upload_type',
            'upload_lane',
            'shoot_service_id',
            'bracket_mode',
            'upload_batch_id',
            'upload_batch_index',
            'upload_batch_total',
            'media_type',
            'is_extra',
            'required_for_editing',
            'requiredForEditing',
            'service_category',
            'photographer_notes',
            'editor_notes',
        ]))->sortKeys()->all();

        return hash('sha256', json_encode([
            'files' => $fileParts,
            'settings' => $settings,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    private function typedError(string $type, string $message, bool $retryable = false): array
    {
        return [
            'error_type' => $type,
            'message' => $message,
            'uploaded_files' => [],
            'errors' => [[
                'error_type' => $type,
                'message' => $message,
                'retryable' => $retryable,
            ]],
            'success_count' => 0,
            'error_count' => 1,
            'partial_success' => false,
        ];
    }
}
