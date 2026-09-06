<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Shoot;
use App\Services\Shoots\Actions\UploadShootFilesAction;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\UploadIntakeResolver;
use App\Services\UploadSourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class UploadSourceController extends Controller
{
    public function __construct(
        protected UploadSourceService $uploadSources,
        protected UploadShootFilesAction $uploadShootFiles,
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
        protected UploadIntakeResolver $uploadIntake
    ) {}

    public function index(Request $request)
    {
        if (! $this->canUseUploadSources($request)) {
            return $this->sourceForbiddenResponse();
        }

        return response()->json([
            'providers' => $this->uploadSources->statuses($request->user()),
        ]);
    }

    public function connect(Request $request, string $provider)
    {
        if (! $this->canUseUploadSources($request)) {
            return $this->sourceForbiddenResponse();
        }

        $validated = $request->validate([
            'account_type' => 'nullable|string|in:personal,shared',
        ]);

        $accountType = ($validated['account_type'] ?? 'personal') === 'shared' ? 'shared' : 'personal';
        if ($provider === 'dropbox' && $accountType === 'shared') {
            return response()->json(['error_type' => 'forbidden', 'message' => 'Manage the studio Dropbox connection from Integrations.'], 403);
        }
        $user = $request->user();
        if ($accountType === 'shared' && ! in_array($user?->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json([
                'error_type' => 'forbidden',
                'message' => 'Only admins can connect shared upload source accounts.',
            ], 403);
        }

        return response()->json([
            'auth_url' => $this->uploadSources->buildAuthorizationUrl($provider, $user, $accountType),
        ]);
    }

    public function callback(Request $request, string $provider)
    {
        try {
            $request->validate([
                'code' => 'required|string',
                'state' => 'required|string',
            ]);

            $this->uploadSources->completeAuthorization(
                $provider,
                (string) $request->query('code'),
                (string) $request->query('state')
            );

            return response(
                '<!doctype html><title>Connected</title><body style="font-family:system-ui;padding:32px">Upload source connected. You can close this window.</body><script>window.opener&&window.opener.postMessage({type:"upload-source-connected",provider:"'.e($provider).'"},"*"); window.close();</script>',
                200,
                ['Content-Type' => 'text/html']
            );
        } catch (Throwable $e) {
            return response(
                '<!doctype html><title>Connection failed</title><body style="font-family:system-ui;padding:32px">Could not connect upload source: '.e($provider === 'dropbox' ? 'Start the Dropbox connection again from the upload source picker. Studio connections are managed in Integrations.' : $e->getMessage()).'</body>',
                400,
                ['Content-Type' => 'text/html']
            );
        }
    }

    public function disconnect(Request $request, string $provider)
    {
        if (! $this->canUseUploadSources($request)) {
            return $this->sourceForbiddenResponse();
        }

        $this->uploadSources->disconnect($provider, $request->user());

        return response()->json(['message' => 'Upload source disconnected.']);
    }

    public function items(Request $request, string $provider)
    {
        if (! $this->canUseUploadSources($request)) {
            return $this->sourceForbiddenResponse();
        }

        try {
            return response()->json($this->uploadSources->listItems($provider, $request->user(), $request->query()));
        } catch (Throwable $e) {
            return response()->json([
                'error_type' => 'source_unavailable',
                'message' => $provider === 'dropbox' ? 'Dropbox is unavailable. Connect an authorized account and try again.' : $e->getMessage(),
            ], 409);
        }
    }

    public function import(Request $request, Shoot $shoot)
    {
        $shootServiceId = $request->filled('shoot_service_id')
            ? (int) $request->input('shoot_service_id')
            : null;

        if (! $this->shootAuthorizationSupport->canUploadShootMedia(
            $shoot,
            $request->user(),
            (string) $request->input('upload_type', 'raw'),
            $shootServiceId
        )) {
            return response()->json([
                'error_type' => 'forbidden',
                'message' => 'You do not have permission to upload media for this shoot.',
                'uploaded_files' => [],
                'errors' => [[
                    'error_type' => 'forbidden',
                    'message' => 'You do not have permission to upload media for this shoot.',
                    'retryable' => false,
                ]],
                'success_count' => 0,
                'error_count' => 1,
                'partial_success' => false,
            ], 403);
        }

        $validated = $request->validate([
            'upload_type' => 'nullable|string|in:raw,edited',
            'source_type' => 'required|string|in:url,provider',
            'provider' => 'nullable|string',
            'urls' => 'nullable|array',
            'urls.*' => 'required_with:urls|string|max:2048',
            'items' => 'nullable|array',
            'items.*.id' => 'nullable|string',
            'items.*.path' => 'nullable|string',
            'items.*.name' => 'nullable|string',
            'items.*.mime_type' => 'nullable|string',
            'bracket_mode' => 'nullable',
            'media_type' => 'nullable|string',
            'is_extra' => 'nullable|boolean',
            'service_category' => 'nullable|string',
            'shoot_service_id' => 'nullable|integer',
            'upload_lane' => 'nullable|string|in:photo,video',
            'idempotency_key' => 'nullable|string|max:191',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
        ]);

        // Fail before fetching anything when the chosen service cannot receive uploads
        // at all. The shared upload action re-checks the specific lane once the real
        // mime types are known; this only avoids downloading source files for a
        // service that was never a valid target, such as a dedicated tour product.
        if ($shootServiceId && ($validated['upload_type'] ?? 'raw') === 'raw') {
            $selectedItem = $shoot->serviceItems()->with('service')->whereKey($shootServiceId)->first();

            if ($selectedItem && $this->uploadIntake->intakeTypeFor($selectedItem) === Service::INTAKE_NONE) {
                return response()->json([
                    'error_type' => 'service_item_not_uploadable',
                    'message' => 'This service does not accept media uploads. Choose a service that covers this media.',
                    'uploaded_files' => [],
                    'errors' => [[
                        'error_type' => 'service_item_not_uploadable',
                        'message' => 'This service does not accept media uploads.',
                        'retryable' => false,
                    ]],
                    'success_count' => 0,
                    'error_count' => 1,
                    'partial_success' => false,
                ], 422);
            }
        }

        $entries = $validated['source_type'] === 'url'
            ? collect($validated['urls'] ?? [])->map(fn ($url) => ['url' => $url])->values()->all()
            : ($validated['items'] ?? []);

        if (empty($entries)) {
            return response()->json([
                'error_type' => 'invalid_source',
                'message' => 'Select at least one source file to import.',
            ], 422);
        }

        $uploadBatchId = count($entries) > 1 ? (string) Str::uuid() : null;
        $idempotencyBase = trim((string) ($validated['idempotency_key'] ?? $request->header('Idempotency-Key', '')));
        if ($idempotencyBase === '') {
            $idempotencyBase = (string) Str::uuid();
        }
        $uploadedFiles = [];
        $errors = [];
        $latestPayload = [];

        foreach ($entries as $index => $entry) {
            $uploadedFile = null;
            try {
                $uploadedFile = $validated['source_type'] === 'url'
                    ? $this->uploadSources->makeUploadedFileFromUrl((string) $entry['url'])
                    : $this->uploadSources->makeUploadedFileFromProviderItem((string) $validated['provider'], $request->user(), $entry);

                $uploadRequest = Request::create('/source-upload', 'POST', $this->buildUploadPayload(
                    $validated,
                    $uploadBatchId,
                    $index,
                    count($entries),
                    'source:'.hash('sha256', $idempotencyBase.':'.$index)
                ));
                $uploadRequest->headers->set('Content-Length', (string) ($uploadedFile->getSize() ?: 0));
                $uploadRequest->files->set('files', [$uploadedFile]);

                $result = $this->uploadShootFiles->execute($uploadRequest, $shoot->fresh(), $request->user());
                $payload = $result['payload'] ?? [];
                $latestPayload = $payload;

                if (($result['status'] ?? 500) >= 300 || ! empty($payload['errors'])) {
                    foreach (($payload['errors'] ?? []) as $error) {
                        $errors[] = $error;
                    }
                    if (empty($payload['uploaded_files'])) {
                        $errors[] = [
                            'filename' => $uploadedFile->getClientOriginalName(),
                            'file_name' => $uploadedFile->getClientOriginalName(),
                            'error_type' => $payload['error_type'] ?? 'source_import_failed',
                            'message' => $payload['message'] ?? 'Import failed.',
                            'retryable' => true,
                        ];
                    }
                }

                $uploadedFiles = array_merge($uploadedFiles, $payload['uploaded_files'] ?? []);
            } catch (Throwable $e) {
                $errors[] = [
                    'filename' => $entry['name'] ?? $entry['url'] ?? 'Source file',
                    'file_name' => $entry['name'] ?? $entry['url'] ?? 'Source file',
                    'error_type' => 'source_import_failed',
                    'message' => $e->getMessage(),
                    'retryable' => true,
                ];
            } finally {
                if ($uploadedFile && file_exists($uploadedFile->getPathname())) {
                    @unlink($uploadedFile->getPathname());
                }
            }
        }

        return response()->json(array_merge($latestPayload, [
            'message' => count($errors) > 0 && count($uploadedFiles) > 0 ? 'Source files imported with some errors.' : 'Source files imported.',
            'uploaded_files' => $uploadedFiles,
            'errors' => $errors,
            'success_count' => count($uploadedFiles),
            'error_count' => count($errors),
            'partial_success' => count($uploadedFiles) > 0 && count($errors) > 0,
        ]), 200);
    }

    private function buildUploadPayload(
        array $validated,
        ?string $batchId,
        int $index,
        int $total,
        string $idempotencyKey
    ): array {
        $payload = collect($validated)
            ->only([
                'upload_type',
                'bracket_mode',
                'media_type',
                'is_extra',
                'service_category',
                'shoot_service_id',
                // Forwarded so the shared upload action applies the same lane
                // eligibility here as it does for direct uploads.
                'upload_lane',
                'photographer_notes',
                'editor_notes',
            ])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $payload['idempotency_key'] = mb_substr($idempotencyKey, 0, 191);

        if ($batchId) {
            $payload['upload_batch_id'] = $batchId;
            $payload['upload_batch_total'] = $total;
            $payload['upload_batch_index'] = $index;
        }

        return $payload;
    }

    private function canUseUploadSources(Request $request): bool
    {
        return $this->shootAuthorizationSupport->hasRole($request->user(), [
            'admin',
            'superadmin',
            'editing_manager',
            'photographer',
            'editor',
        ]);
    }

    private function sourceForbiddenResponse()
    {
        return response()->json([
            'error_type' => 'forbidden',
            'message' => 'You do not have permission to access upload sources.',
        ], 403);
    }
}
