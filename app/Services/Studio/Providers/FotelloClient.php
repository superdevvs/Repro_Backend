<?php

namespace App\Services\Studio\Providers;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Server-only adapter for https://app.fotello.co/api-docs (OpenAPI 1.0.0).
 * IDs/URIs must come from the caller's authorized workspace checkpoints, never browser input.
 * One request per call: the API documents no idempotency key. Persist returned IDs immediately;
 * an ambiguous mutation must be reconciled, not automatically resubmitted.
 */
class FotelloClient
{
    private const API = 'https://api.fotello.co/v1/';

    private const ENHANCE_PREFERENCES = [
        'contrast_style', 'perspective_correction', 'exterior_sky_replacement', 'interior_sky_replacement',
        'custom_style_id', 'cloud_style', 'custom_cloud_uri', 'interior_cloud_style', 'interior_custom_cloud_uri',
    ];

    private array $configuration;

    private FotelloTransferUrl $transfers;

    public function __construct(#[\SensitiveParameter] array $configuration = [], ?FotelloTransferUrl $transfers = null)
    {
        $this->configuration = array_replace((array) config('studio_providers.fotello', []), $configuration);
        $hosts = $this->configuration['allowed_transfer_hosts'] ?? [];
        $hosts = is_string($hosts) ? array_filter(array_map('trim', explode(',', $hosts))) : $hosts;
        $this->transfers = $transfers ?? new FotelloTransferUrl(is_array($hosts) ? $hosts : []);
    }

    public function __debugInfo(): array
    {
        return ['configured' => ! empty($this->configuration['api_key']) && ! empty($this->configuration['team_id'])];
    }

    public function createListing(#[\SensitiveParameter] array $payload): array
    {
        $rules = [
            'name' => 'required|string', 'num_total_brackets' => 'sometimes|numeric', 'filenames' => 'sometimes|array',
            'filenames.*' => 'string', 'isDemoListing' => 'sometimes|boolean', 'charge_photo_edit' => 'sometimes|boolean',
            'address' => 'sometimes|array:formattedAddress,coordinates,addressLine1,addressLine2,city,state,postalCode,country,countryCode',
            'address.coordinates' => 'sometimes|array:latitude,longitude', 'address.coordinates.latitude' => 'sometimes|numeric',
            'address.coordinates.longitude' => 'sometimes|numeric',
        ];
        foreach (['formattedAddress', 'addressLine1', 'addressLine2', 'city', 'state', 'postalCode', 'country', 'countryCode'] as $field) {
            $rules['address.'.$field] = 'sometimes|string';
        }

        return $this->idResponse($this->post('create-listing', $this->payload($payload, $rules)));
    }

    public function createUpload(#[\SensitiveParameter] array $payload): array
    {
        $data = $this->post('create-upload', $this->payload($payload, [
            'filename' => 'required|string', 'uploadType' => 'sometimes|string', 'listingId' => 'sometimes|string',
        ]));
        $this->requireStrings($data, ['id', 'url', 'uri', 'expires'], true);
        $this->transfers->validate($data['url']);

        return array_intersect_key($data, array_flip(['id', 'url', 'uri', 'expires']));
    }

    /** PUT the exact create-upload URL with no API credentials, as documented. */
    public function uploadBytes(#[\SensitiveParameter] array $upload, #[\SensitiveParameter] string $bytes): void
    {
        $this->requireStrings($upload, ['id', 'url', 'uri']);
        if ($bytes === '' || strlen($bytes) > $this->maxTransferBytes()) {
            throw new FotelloException('transfer_limit');
        }
        $request = Http::withOptions($this->transfers->requestOptions($upload['url']))->setHandler(new CurlHandler)
            ->timeout($this->transferTimeout())->connectTimeout(15)->withBody($bytes, 'application/octet-stream');
        $response = $this->send(fn () => $request->put($upload['url']), false);
        $this->checkStatus($response, false);
    }

    public function createEnhance(#[\SensitiveParameter] array $payload): array
    {
        $rules = [
            'upload_ids' => 'required|array|min:1', 'upload_ids.*' => 'required|string', 'listing_id' => 'required|string',
            'earliest_captured_at' => 'sometimes|date', 'shot_type' => 'sometimes|in:interior,exterior',
            'input_preview_image_id' => 'sometimes|string', 'preferences' => 'sometimes|array:'.implode(',', self::ENHANCE_PREFERENCES),
        ];
        foreach (self::ENHANCE_PREFERENCES as $field) {
            $rules['preferences.'.$field] = 'sometimes|string';
        }
        $body = $this->payload($payload, $rules);
        if (isset($body['preferences']) && $body['preferences'] === []) {
            $body['preferences'] = (object) [];
        }

        return $this->idResponse($this->post('create-enhance', $body));
    }

    /** A single resumable poll. A failed status is returned as failed, never inferred as completed. */
    public function getEnhance(string $id): array
    {
        $this->requireStrings(['id' => $id], ['id']);
        $response = $this->send(fn () => $this->request()->get(self::API.'get-enhance', ['id' => $id]), false);
        $data = $this->json($response, false);
        if (($data['id'] ?? null) !== $id || ! in_array($data['status'] ?? null, ['pending', 'in_progress', 'completed', 'failed'], true)) {
            throw new FotelloException('response_contract');
        }
        $url = $data['enhanced_image_url'] ?? null;
        if ($url !== null) {
            if (! is_string($url) || $url === '') {
                throw new FotelloException('response_contract');
            }
            $this->transfers->validate($url);
        } elseif ($data['status'] === 'completed') {
            throw new FotelloException('response_contract');
        }
        $expires = $data['enhanced_image_url_expires'] ?? null;
        if ($expires !== null && ! is_string($expires)) {
            throw new FotelloException('response_contract');
        }

        return ['id' => $id, 'status' => $data['status'], 'enhanced_image_url' => $url, 'enhanced_image_url_expires' => $expires];
    }

    public function updateEnhance(#[\SensitiveParameter] array $payload): array
    {
        return $this->render('update-enhance', $payload, false);
    }

    public function updateAsset(#[\SensitiveParameter] array $payload): array
    {
        return $this->render('update-asset', $payload, true);
    }

    /** success=true acknowledges submission; the API does not document a revision completion poll. */
    public function createAiRevision(#[\SensitiveParameter] array $payload): array
    {
        $data = $this->post('create-ai-revision', $this->payload($payload, [
            'variant_id' => 'required|string', 'input_image_uri' => 'required|string', 'selected_output_uri' => 'required|string',
            'prompt' => 'required|string', 'model' => 'sometimes|in:standard,pro', 'reference_images' => 'sometimes|array',
            'reference_images.*' => 'array:uri,filename', 'reference_images.*.uri' => 'required|string',
            'reference_images.*.filename' => 'sometimes|string',
        ]));
        if (($data['success'] ?? null) === false) {
            throw new FotelloException('revision_rejected');
        }
        if (($data['success'] ?? null) !== true) {
            throw new FotelloException('response_contract', ambiguousOutcome: true);
        }

        return ['success' => true];
    }

    /** The documented {} response means started, not finished. */
    public function createUpscale(#[\SensitiveParameter] array $payload): array
    {
        $data = $this->post('create-upscale', $this->payload($payload, [
            'variant_id' => 'required|string', 'input_image_uri' => 'required|string', 'selected_output_uri' => 'required|string',
        ]));
        if ($data !== []) {
            throw new FotelloException('response_contract', ambiguousOutcome: true);
        }

        return [];
    }

    public function prepareDownload(#[\SensitiveParameter] array $payload): array
    {
        $data = $this->post('prepare-download', $this->payload($payload, [
            'listing_id' => 'required|string', 'sections' => 'sometimes|array', 'sections.*' => 'in:photos,videos,floor_plans,virtual_tours,designs',
            'photo_formats' => 'sometimes|array', 'photo_formats.*' => 'in:mls,print,original',
            'design_formats' => 'sometimes|array', 'design_formats.*' => 'in:jpeg,png,pdf',
        ]));
        $this->requireStrings($data, ['download_url'], true);
        foreach (['total_items', 'processed_items'] as $key) {
            if (! isset($data[$key]) || ! is_numeric($data[$key]) || $data[$key] < 0) {
                throw new FotelloException('response_contract', ambiguousOutcome: true);
            }
        }
        $this->transfers->validate($data['download_url']);

        return array_intersect_key($data, array_flip(['download_url', 'total_items', 'processed_items']));
    }

    /** Downloads only validated public HTTPS locations without forwarding API credentials. */
    public function downloadBytes(#[\SensitiveParameter] string $url): string
    {
        $options = $this->transfers->requestOptions($url);
        $limit = $this->maxTransferBytes();
        // Force cURL so a stream-handler fallback cannot bypass CURLOPT_RESOLVE DNS pinning.
        $options['progress'] = static function ($total, $downloaded) use ($limit): void {
            if ($total > $limit || $downloaded > $limit) {
                throw new FotelloException('transfer_limit');
            }
        };
        $response = $this->send(fn () => Http::withOptions($options)->setHandler(new CurlHandler)
            ->timeout($this->transferTimeout())->connectTimeout(15)->get($url), false);
        $this->checkStatus($response, false);
        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';
        try {
            if ((int) $response->header('Content-Length') > $this->maxTransferBytes()) {
                throw new FotelloException('transfer_limit');
            }
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '' && ! $stream->eof()) {
                    throw new FotelloException('unavailable', retryable: true);
                }
                $bytes .= $chunk;
                if (strlen($bytes) > $this->maxTransferBytes()) {
                    throw new FotelloException('transfer_limit');
                }
            }
        } catch (FotelloException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new FotelloException('unavailable', retryable: true);
        } finally {
            $stream->close();
        }
        if ($bytes === '') {
            throw new FotelloException('response_contract');
        }

        return $bytes;
    }

    private function render(string $endpoint, #[\SensitiveParameter] array $payload, bool $asset): array
    {
        $body = $this->payload($payload, [
            'id' => 'required|string', 'variant' => ($asset ? 'required' : 'sometimes').'|array:type,renderType,preferences|required_array_keys:type,preferences',
            'variant.type' => 'required_with:variant|in:edit_image,virtual_staging,twilight',
            'variant.renderType' => 'sometimes|string', 'variant.preferences' => 'present_with:variant|array',
            'realtorUserId' => 'sometimes|string', 'override' => 'sometimes|boolean',
        ]);
        if (isset($body['variant']['preferences']) && $body['variant']['preferences'] !== [] && array_is_list($body['variant']['preferences'])) {
            throw new FotelloException('request_contract');
        }
        // Variant preferences are explicitly opaque in the reference. Do not invent their shape or defaults.
        if (isset($body['variant']['preferences']) && $body['variant']['preferences'] === []) {
            $body['variant']['preferences'] = (object) [];
        }
        $data = $this->post($endpoint, $body);
        $fields = [$asset ? 'assetId' : 'enhanceId', 'variantId', 'renderId', 'orderId'];
        $this->requireStrings($data, $fields, true);
        if ($data[$fields[0]] !== $body['id']) {
            throw new FotelloException('response_contract', ambiguousOutcome: true);
        }

        return array_intersect_key($data, array_flip($fields));
    }

    private function payload(#[\SensitiveParameter] array $payload, array $rules): array
    {
        $this->ensureConfigured();
        $team = (string) ($this->configuration['team_id'] ?? '');
        if (array_key_exists('teamId', $payload) && $payload['teamId'] !== $team) {
            throw new FotelloException('request_contract');
        }
        $allowed = array_unique(array_map(fn ($key) => explode('.', $key)[0], array_keys($rules)));
        if (array_diff(array_keys($payload), [...$allowed, 'teamId']) !== [] || Validator::make($payload, $rules)->fails()) {
            throw new FotelloException('request_contract');
        }

        return array_merge($payload, ['teamId' => $team]);
    }

    private function idResponse(#[\SensitiveParameter] array $data): array
    {
        $this->requireStrings($data, ['id'], true);

        return ['id' => $data['id']];
    }

    private function requireStrings(#[\SensitiveParameter] array $data, array $fields, bool $response = false): void
    {
        foreach ($fields as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || trim($data[$field]) === '') {
                throw new FotelloException($response ? 'response_contract' : 'request_contract', ambiguousOutcome: $response);
            }
        }
    }

    private function post(string $endpoint, #[\SensitiveParameter] array $body): array
    {
        return $this->json($this->send(fn () => $this->request()->post(self::API.$endpoint, $body), true), true);
    }

    private function request(): PendingRequest
    {
        $this->ensureConfigured();

        return Http::acceptJson()->asJson()->withToken((string) $this->configuration['api_key'])
            ->timeout(max(1, (int) ($this->configuration['timeout'] ?? 60)))->connectTimeout(15)
            ->withOptions(['allow_redirects' => false, 'verify' => true]);
    }

    private function ensureConfigured(): void
    {
        if (trim((string) ($this->configuration['api_key'] ?? '')) === '' || trim((string) ($this->configuration['team_id'] ?? '')) === '') {
            throw new FotelloException('configuration');
        }
    }

    private function send(#[\SensitiveParameter] callable $send, bool $mutation): Response
    {
        try {
            return $send();
        } catch (FotelloException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new FotelloException('unavailable', retryable: ! $mutation, ambiguousOutcome: $mutation);
        }
    }

    private function json(#[\SensitiveParameter] Response $response, bool $mutation): array
    {
        $this->checkStatus($response, $mutation);
        $body = trim($response->body());
        $data = $response->json();
        if (! str_starts_with($body, '{') || ! is_array($data)) {
            throw new FotelloException('response_contract', ambiguousOutcome: $mutation);
        }

        return $data;
    }

    private function checkStatus(#[\SensitiveParameter] Response $response, bool $mutation): void
    {
        if ($response->successful()) {
            return;
        }
        $status = $response->status();
        $transient = in_array($status, [408, 409, 425, 429], true) || $status >= 500;
        $ambiguous = $mutation && ($status === 408 || $status >= 500);
        $category = in_array($status, [401, 402, 403], true) ? 'account' : ($transient ? 'unavailable' : 'rejected');
        throw new FotelloException($category, $status, $transient && ! $ambiguous, $ambiguous);
    }

    private function maxTransferBytes(): int
    {
        return max(1, min(536870912, (int) ($this->configuration['max_transfer_bytes'] ?? 268435456)));
    }

    private function transferTimeout(): int
    {
        return max(1, (int) ($this->configuration['transfer_timeout'] ?? 180));
    }
}
