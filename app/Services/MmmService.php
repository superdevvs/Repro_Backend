<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MmmService
{
    private bool $enabled;
    private ?string $duns;
    private ?string $sharedSecret;
    private string $userAgent;
    private ?string $punchoutUrl;
    private ?string $templateExternalNumber;
    private string $deploymentMode;
    private string $startPoint;
    private string $toIdentity;
    private string $senderIdentity;
    private string $urlReturn;
    private int $timeout;

    public function __construct(
        private readonly MmmXmlBuilder $xmlBuilder,
        private readonly DropboxWorkflowService $dropboxService,
    ) {
        $settings = $this->loadSettings('integrations.mmm');

        $this->enabled = (bool) ($settings['enabled'] ?? config('services.mmm.enabled', true));
        $this->duns = $settings['duns'] ?? config('services.mmm.duns');
        $this->sharedSecret = $settings['sharedSecret'] ?? config('services.mmm.shared_secret');
        $this->userAgent = $settings['userAgent'] ?? config('services.mmm.user_agent', 'REPro Photos');
        $this->punchoutUrl = $settings['punchoutUrl'] ?? config('services.mmm.punchout_url');
        $this->templateExternalNumber = $settings['templateExternalNumber'] ?? config('services.mmm.template_external_number');
        $this->deploymentMode = $settings['deploymentMode'] ?? config('services.mmm.deployment_mode', 'test');
        $this->startPoint = $settings['startPoint'] ?? config('services.mmm.start_point', 'Category');
        $this->toIdentity = $settings['toIdentity'] ?? config('services.mmm.to_identity', '');
        $this->senderIdentity = $settings['senderIdentity'] ?? config('services.mmm.sender_identity', '');
        $this->urlReturn = $settings['urlReturn'] ?? config('services.mmm.url_return');
        $this->timeout = (int) ($settings['timeout'] ?? config('services.mmm.timeout', 20));
    }

    public function parsePunchoutOrderMessage(string $xml): array
    {
        $result = [
            'buyer_cookie' => null,
            'order_number' => null,
            'raw' => $xml,
        ];

        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);

            $buyerCookieNode = $dom->getElementsByTagName('BuyerCookie')->item(0);
            if ($buyerCookieNode) {
                $result['buyer_cookie'] = trim($buyerCookieNode->textContent);
            }

            $supplierPartIdNode = $dom->getElementsByTagName('SupplierPartID')->item(0);
            if ($supplierPartIdNode) {
                $result['order_number'] = trim($supplierPartIdNode->textContent);
            }
        } catch (\Exception $e) {
            Log::warning('MMM order message parse error', ['error' => $e->getMessage()]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function validateConfig(): ?array
    {
        if (!$this->enabled) {
            return ['success' => false, 'status' => 'disabled', 'error' => 'MMM integration is disabled'];
        }

        $missing = [];
        foreach ([
            'MMM_DUNS' => $this->duns,
            'MMM_SHARED_SECRET' => $this->sharedSecret,
            'MMM_PUNCHOUT_URL' => $this->punchoutUrl,
            'MMM_TEMPLATE_EXTERNAL_NUMBER' => $this->templateExternalNumber,
        ] as $label => $value) {
            if (empty($value)) {
                $missing[] = $label;
            }
        }

        if (!empty($missing)) {
            return [
                'success' => false,
                'status' => 'config_error',
                'error' => 'Missing MMM configuration values: ' . implode(', ', $missing),
                'missing' => $missing,
            ];
        }

        return null;
    }

    public function buildPunchoutPayload(Shoot $shoot, array $params = []): array
    {
        $user = $params['user'] ?? null;
        $nameParts = $this->splitName($user);

        $propertyDetails = $shoot->property_details ?? [];
        $address = $this->formatAddress($shoot, $propertyDetails);
        $price = $propertyDetails['price'] ?? $propertyDetails['price_high'] ?? $propertyDetails['price_low'] ?? null;
        $mlsId = $shoot->mls_id ?? $propertyDetails['mls_id'] ?? null;

        $pictures = $this->buildPictures($shoot, $params['file_ids'] ?? []);

        return [
            'duns' => $this->duns,
            'shared_secret' => $this->sharedSecret,
            'user_agent' => $this->userAgent,
            'buyer_cookie' => $params['buyer_cookie'] ?? Str::uuid()->toString(),
            'cost_center_number' => $params['cost_center_number'] ?? null,
            'employee_email' => $params['employee_email'] ?? $user?->email,
            'username' => $params['username'] ?? $user?->username ?? $user?->email,
            'first_name' => $params['first_name'] ?? $nameParts['first'],
            'last_name' => $params['last_name'] ?? $nameParts['last'],
            'start_point' => $params['start_point'] ?? $this->startPoint,
            'artwork_url' => $this->resolveArtworkUrl($shoot, $params),
            'template_external_number' => $params['template_external_number'] ?? $this->templateExternalNumber,
            'deployment_mode' => $params['deployment_mode'] ?? $this->deploymentMode,
            'url_return' => $params['url_return'] ?? $this->urlReturn,
            'to_identity' => $params['to_identity'] ?? $this->toIdentity,
            'sender_identity' => $params['sender_identity'] ?? $this->senderIdentity,
            'properties' => [
                [
                    'mls_id' => $params['mls_id'] ?? $mlsId,
                    'price' => $params['price'] ?? $price,
                    'address' => $params['address'] ?? $address,
                    'description' => $params['description'] ?? $shoot->address,
                    'pictures' => $pictures,
                ],
            ],
        ];
    }

    public function sendPunchoutRequest(array $payload): array
    {
        $xml = $this->xmlBuilder->buildPunchoutSetupRequest($payload);
        if (!$xml) {
            return [
                'success' => false,
                'status' => 'xml_error',
                'error' => 'Failed to build MMM punchout XML',
            ];
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeout)
                ->post($this->punchoutUrl, [
                    'xml' => $xml,
                ]);

            $responseBody = $response->body();
            if (!$response->successful()) {
                return [
                    'success' => false,
                    'status' => 'http_error',
                    'error' => 'MMM punchout request failed',
                    'http_status' => $response->status(),
                    'response' => $responseBody,
                ];
            }

            $parsed = $this->xmlBuilder->parsePunchoutSetupResponse($responseBody);

            return [
                'success' => $parsed['success'],
                'status' => $parsed['success'] ? 'ok' : 'error',
                'redirect_url' => $parsed['redirect_url'],
                'status_code' => $parsed['status_code'],
                'status_text' => $parsed['status_text'],
                'request_xml' => $xml,
                'response_xml' => $responseBody,
            ];
        } catch (\Exception $e) {
            Log::error('MMM punchout request exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'status' => 'exception',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function loadSettings(string $key): array
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('settings')) {
                return [];
            }
            $setting = DB::table('settings')->where('key', $key)->first();
            if ($setting && isset($setting->type) && $setting->type === 'json') {
                return json_decode($setting->value, true) ?? [];
            }
        } catch (\Exception $e) {
            Log::warning('Could not load settings from database', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    private function splitName(?User $user): array
    {
        $name = trim((string) ($user?->name ?? ''));
        if ($name === '') {
            return ['first' => null, 'last' => null];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $first = $parts[0] ?? null;
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;

        return ['first' => $first, 'last' => $last];
    }

    private function formatAddress(Shoot $shoot, array $propertyDetails = []): string
    {
        $address = $propertyDetails['address']['formatted'] ?? null;
        if ($address) {
            return $address;
        }

        return trim(implode(', ', array_filter([
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip,
        ])));
    }

    private function resolveArtworkUrl(Shoot $shoot, array $params = []): ?string
    {
        if (!empty($params['artwork_url'])) {
            return $params['artwork_url'];
        }

        $artworkFileId = $params['artwork_file_id'] ?? null;
        if ($artworkFileId) {
            $file = $shoot->files->firstWhere('id', (int) $artworkFileId);
            if ($file) {
                return $this->resolveFileUrl($file);
            }
        }

        $pdfFile = $shoot->files->first(function (ShootFile $file) {
            $filename = strtolower($file->stored_filename ?? $file->filename ?? '');
            $mime = strtolower($file->mime_type ?? $file->file_type ?? '');
            return str_contains($mime, 'pdf') || str_ends_with($filename, '.pdf');
        });

        return $pdfFile ? $this->resolveFileUrl($pdfFile) : null;
    }

    private function buildPictures(Shoot $shoot, array $fileIds = []): array
    {
        $files = $shoot->files;
        if (!empty($fileIds)) {
            $ids = array_map('intval', $fileIds);
            $files = $files->whereIn('id', $ids);
        } else {
            $files = $files->whereIn('workflow_stage', [ShootFile::STAGE_VERIFIED, ShootFile::STAGE_COMPLETED]);
        }

        return $files->filter(function (ShootFile $file) {
            $mime = strtolower($file->mime_type ?? $file->file_type ?? '');
            $filename = strtolower($file->stored_filename ?? $file->filename ?? '');
            return str_starts_with($mime, 'image/') || preg_match('/\.(jpg|jpeg|png|gif|webp)$/', $filename);
        })->map(function (ShootFile $file) {
            return [
                'id' => (string) $file->id,
                'caption' => $file->filename ?? $file->stored_filename ?? '',
                'filename' => $file->stored_filename ?? $file->filename ?? '',
                'url' => $this->resolveFileUrl($file),
            ];
        })->filter(function ($picture) {
            return !empty($picture['url']);
        })->values()->all();
    }

    private function resolveFileUrl(ShootFile $file): ?string
    {
        if ($file->url) {
            return $file->url;
        }

        $path = $file->storage_path ?: $file->path;
        if ($path && Str::startsWith($path, 'http')) {
            return $path;
        }

        if ($path && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        if ($path && !Str::startsWith($path, 'http') && !$file->dropbox_path) {
            return Storage::disk('public')->url($path);
        }

        if ($file->dropbox_path) {
            return $this->dropboxService->getTemporaryLink($file->dropbox_path);
        }

        return null;
    }
}
