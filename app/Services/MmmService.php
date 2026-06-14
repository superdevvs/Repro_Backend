<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Http\Client\Response;
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

        $this->enabled = (bool) $this->resolveSettingValue($settings, 'enabled', config('services.mmm.enabled', true));
        $this->duns = $this->resolveSettingValue($settings, 'duns', config('services.mmm.duns'));
        $this->sharedSecret = $this->resolveSettingValue($settings, 'sharedSecret', config('services.mmm.shared_secret'));
        $this->userAgent = (string) $this->resolveSettingValue($settings, 'userAgent', config('services.mmm.user_agent', 'REPro Photos'));
        $this->punchoutUrl = $this->resolveSettingValue($settings, 'punchoutUrl', config('services.mmm.punchout_url'));
        $this->templateExternalNumber = $this->resolveSettingValue($settings, 'templateExternalNumber', config('services.mmm.template_external_number'));
        $this->deploymentMode = (string) $this->resolveSettingValue($settings, 'deploymentMode', config('services.mmm.deployment_mode', 'test'));
        $this->startPoint = (string) $this->resolveSettingValue($settings, 'startPoint', config('services.mmm.start_point', 'category'));
        $this->toIdentity = (string) $this->resolveSettingValue($settings, 'toIdentity', config('services.mmm.to_identity', ''));
        $this->senderIdentity = (string) $this->resolveSettingValue($settings, 'senderIdentity', config('services.mmm.sender_identity', ''));
        $this->urlReturn = (string) $this->resolveSettingValue($settings, 'urlReturn', config('services.mmm.url_return'));
        $this->timeout = (int) $this->resolveSettingValue($settings, 'timeout', config('services.mmm.timeout', 20));
    }

    public function parsePunchoutOrderMessage(string $xml): array
    {
        $result = [
            'buyer_cookie' => null,
            'order_number' => null,
            'items' => [],
            'subtotal' => null,
            'tax' => null,
            'shipping' => null,
            'total' => null,
            'currency' => null,
            'raw' => $xml,
        ];

        try {
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $dom->loadXML($xml);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $buyerCookieNode = $dom->getElementsByTagName('BuyerCookie')->item(0);
            if ($buyerCookieNode) {
                $result['buyer_cookie'] = trim($buyerCookieNode->textContent);
            }

            $supplierPartIdNode = $dom->getElementsByTagName('SupplierPartID')->item(0);
            if ($supplierPartIdNode) {
                $result['order_number'] = trim($supplierPartIdNode->textContent);
            }

            $itemInNodes = $dom->getElementsByTagName('ItemIn');
            $sumExtended = 0.0;
            $itemCurrency = null;
            foreach ($itemInNodes as $index => $itemNode) {
                try {
                    $item = $this->parseItemInNode($itemNode, $index + 1);
                    if ($item === null) {
                        continue;
                    }
                    if (is_numeric($item['extended_price'] ?? null)) {
                        $sumExtended += (float) $item['extended_price'];
                    }
                    if (!$itemCurrency && !empty($item['currency'])) {
                        $itemCurrency = $item['currency'];
                    }
                    $result['items'][] = $item;
                } catch (\Exception $itemError) {
                    Log::warning('MMM order item parse error', [
                        'index' => $index,
                        'error' => $itemError->getMessage(),
                    ]);
                }
            }

            if ($itemCurrency) {
                $result['currency'] = $itemCurrency;
            }

            $totalNode = $this->findMoneyChild($dom, 'Total');
            if ($totalNode) {
                $result['total'] = $this->toFloat($totalNode->textContent);
                $currencyAttr = $totalNode->getAttribute('currency');
                if ($currencyAttr) {
                    $result['currency'] = $currencyAttr;
                }
            } elseif ($sumExtended > 0) {
                $result['total'] = round($sumExtended, 2);
            }

            $subtotalNode = $this->findMoneyChild($dom, 'Subtotal');
            if ($subtotalNode) {
                $result['subtotal'] = $this->toFloat($subtotalNode->textContent);
            } elseif ($sumExtended > 0) {
                $result['subtotal'] = round($sumExtended, 2);
            }

            $taxNode = $this->findMoneyChild($dom, 'Tax');
            if ($taxNode) {
                $result['tax'] = $this->toFloat($taxNode->textContent);
            }

            $shippingNode = $this->findMoneyChild($dom, 'Shipping');
            if ($shippingNode) {
                $result['shipping'] = $this->toFloat($shippingNode->textContent);
            }
        } catch (\Exception $e) {
            Log::warning('MMM order message parse error', ['error' => $e->getMessage()]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function parseItemInNode(\DOMNode $itemNode, int $lineNumber): ?array
    {
        if (!$itemNode instanceof \DOMElement) {
            return null;
        }

        $quantity = $this->toFloat($itemNode->getAttribute('quantity'));
        $supplierPartId = $this->firstChildText($itemNode, 'SupplierPartID');
        $description = $this->firstChildText($itemNode, 'Description');
        $unitOfMeasure = $this->firstChildText($itemNode, 'UnitOfMeasure');

        $unitPrice = null;
        $currency = null;
        $moneyNodes = $itemNode->getElementsByTagName('Money');
        if ($moneyNodes->length > 0) {
            $firstMoney = $moneyNodes->item(0);
            if ($firstMoney instanceof \DOMElement) {
                $unitPrice = $this->toFloat($firstMoney->textContent);
                $currency = $firstMoney->getAttribute('currency') ?: null;
            }
        }

        $extended = null;
        if ($unitPrice !== null && $quantity !== null) {
            $extended = round($unitPrice * $quantity, 2);
        }

        return [
            'line_number' => $lineNumber,
            'supplier_part_id' => $supplierPartId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_of_measure' => $unitOfMeasure,
            'unit_price' => $unitPrice,
            'currency' => $currency,
            'extended_price' => $extended,
        ];
    }

    private function firstChildText(\DOMElement $parent, string $tag): ?string
    {
        $nodes = $parent->getElementsByTagName($tag);
        if ($nodes->length === 0) {
            return null;
        }
        $value = trim($nodes->item(0)->textContent ?? '');
        return $value !== '' ? $value : null;
    }

    private function findMoneyChild(\DOMDocument $dom, string $parentTag): ?\DOMElement
    {
        $parents = $dom->getElementsByTagName($parentTag);
        if ($parents->length === 0) {
            return null;
        }
        $parent = $parents->item(0);
        if (!$parent instanceof \DOMElement) {
            return null;
        }
        $moneyNodes = $parent->getElementsByTagName('Money');
        return $moneyNodes->length > 0 && $moneyNodes->item(0) instanceof \DOMElement
            ? $moneyNodes->item(0)
            : null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        return (float) $trimmed;
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
        $pictures = $this->buildPictures($shoot, $params['file_ids'] ?? []);
        $property = $this->buildPropertyPayload($shoot, $propertyDetails, $params, $pictures);

        return [
            'duns' => $this->duns,
            'shared_secret' => $this->sharedSecret,
            'user_agent' => $this->userAgent,
            'buyer_cookie' => $params['buyer_cookie'] ?? Str::uuid()->toString(),
            'cost_center_number' => $params['cost_center_number'] ?? 'Repro',
            'employee_email' => $params['employee_email'] ?? $user?->email,
            'username' => $params['username'] ?? $user?->username ?? $user?->email,
            'first_name' => $params['first_name'] ?? $nameParts['first'],
            'last_name' => $params['last_name'] ?? $nameParts['last'],
            'start_point' => $params['start_point'] ?? $this->startPoint,
            'template_external_number' => $params['template_external_number'] ?? $this->templateExternalNumber,
            'deployment_mode' => $params['deployment_mode'] ?? $this->deploymentMode,
            'url_return' => $params['url_return'] ?? $this->urlReturn,
            'to_identity' => $params['to_identity'] ?? $this->toIdentity,
            'sender_identity' => $params['sender_identity'] ?? $this->senderIdentity,
            'address' => $property['formatted_address'] ?? null,
            'property' => $property,
            'pictures' => $pictures,
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
            $response = $this->sendRawPunchoutRequest($xml);
            $responseBody = $response->body();

            if (!$response->successful()) {
                Log::warning('MMM raw XML punchout request failed, retrying as form payload', [
                    'http_status' => $response->status(),
                ]);

                $response = $this->sendFormPunchoutRequest($xml);
                $responseBody = $response->body();

                if (!$response->successful()) {
                    return $this->buildHttpErrorResult($response, $responseBody, $xml);
                }
            }

            $parsed = $this->xmlBuilder->parsePunchoutSetupResponse($responseBody);
            $parsed['redirect_url'] = $this->resolveRedirectUrl($parsed['redirect_url'] ?? null);
            $parsed['success'] = ($parsed['status_code'] === '200') && !empty($parsed['redirect_url']);

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

    private function sendRawPunchoutRequest(string $xml): Response
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'Accept' => 'text/xml, application/xml, text/plain, */*',
                'Content-Type' => 'text/xml; charset=UTF-8',
            ])
            ->send('POST', $this->punchoutUrl, [
                'body' => $xml,
            ]);
    }

    private function sendFormPunchoutRequest(string $xml): Response
    {
        return Http::asForm()
            ->timeout($this->timeout)
            ->post($this->punchoutUrl, [
                'xml' => $xml,
            ]);
    }

    private function buildHttpErrorResult(Response $response, string $responseBody, string $requestXml): array
    {
        $parsed = $this->xmlBuilder->parsePunchoutSetupResponse($responseBody);
        $errorMessage =
            $parsed['status_text']
            ?? $this->extractResponseErrorMessage($responseBody)
            ?? 'MMM punchout request failed';

        return [
            'success' => false,
            'status' => 'http_error',
            'error' => $errorMessage,
            'http_status' => $response->status(),
            'status_code' => $parsed['status_code'] ?? null,
            'status_text' => $parsed['status_text'] ?? null,
            'request_xml' => $requestXml,
            'response_xml' => $responseBody,
            'response' => $responseBody,
        ];
    }

    private function extractResponseErrorMessage(string $responseBody): ?string
    {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($responseBody)) ?? '');

        return $message !== '' ? Str::limit($message, 300) : null;
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

    private function resolveSettingValue(array $settings, string $key, mixed $fallback): mixed
    {
        if (!array_key_exists($key, $settings)) {
            return $fallback;
        }

        $value = $settings[$key];

        if ($value === null) {
            return $fallback;
        }

        if (is_string($value) && trim($value) === '') {
            return $fallback;
        }

        return $value;
    }

    private function resolveRedirectUrl(?string $redirectUrl): ?string
    {
        $candidate = trim((string) $redirectUrl);
        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $candidate)) {
            return $candidate;
        }

        $baseUrl = trim((string) $this->punchoutUrl);
        $baseParts = $baseUrl !== '' ? parse_url($baseUrl) : false;

        if ($baseParts === false || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return $candidate;
        }

        $origin = sprintf(
            '%s://%s%s',
            $baseParts['scheme'],
            $baseParts['host'],
            isset($baseParts['port']) ? ':' . $baseParts['port'] : '',
        );

        if (str_starts_with($candidate, '//')) {
            return $baseParts['scheme'] . ':' . $candidate;
        }

        $basePath = $baseParts['path'] ?? '/';
        $baseDirectory = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';

        if (str_starts_with($candidate, '/')) {
            return $origin . $candidate;
        }

        if (str_starts_with($candidate, '?')) {
            return $origin . $basePath . $candidate;
        }

        return $origin . $baseDirectory . $candidate;
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

        $lineOne = trim(implode(', ', array_filter([
            $shoot->address,
            $shoot->city,
        ])));
        $stateZip = trim(implode(' ', array_filter([
            $shoot->state,
            $shoot->zip,
        ])));

        return trim(implode(', ', array_filter([
            $lineOne,
            $stateZip,
        ])));
    }

    private function buildPropertyPayload(Shoot $shoot, array $propertyDetails, array $params, array $pictures): array
    {
        $tourLinks = is_array($shoot->tour_links) ? $shoot->tour_links : [];
        $address = $this->resolvePropertyAddress($shoot, $propertyDetails, $params);
        $city = $this->firstFilled([
            $params['city'] ?? null,
            $shoot->city,
            data_get($propertyDetails, 'address.city'),
            data_get($propertyDetails, 'city'),
        ]);
        $state = $this->firstFilled([
            $params['state'] ?? null,
            $shoot->state,
            data_get($propertyDetails, 'address.state'),
            data_get($propertyDetails, 'state'),
        ]);
        $zip = $this->firstFilled([
            $params['zip'] ?? null,
            $shoot->zip,
            data_get($propertyDetails, 'address.zip'),
            data_get($propertyDetails, 'address.postal_code'),
            data_get($propertyDetails, 'address.postalCode'),
            data_get($propertyDetails, 'zip'),
            data_get($propertyDetails, 'postal_code'),
            data_get($propertyDetails, 'postalCode'),
        ]);

        return [
            'id' => $this->firstFilled([
                $params['property_id'] ?? null,
                $params['mls_id'] ?? null,
                $shoot->mls_id,
                data_get($propertyDetails, 'id'),
                data_get($propertyDetails, 'property_id'),
                data_get($propertyDetails, 'mls_id'),
                data_get($propertyDetails, 'listing_id'),
            ]),
            'price' => $this->normalizePrice($this->firstFilled([
                $params['price'] ?? null,
                data_get($propertyDetails, 'price'),
                data_get($propertyDetails, 'list_price'),
                data_get($propertyDetails, 'listPrice'),
                data_get($propertyDetails, 'listing_price'),
                data_get($propertyDetails, 'listingPrice'),
            ])),
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'description' => $this->firstFilled([
                $params['description'] ?? null,
                data_get($tourLinks, 'property_description'),
                data_get($propertyDetails, 'description'),
                data_get($propertyDetails, 'public_remarks'),
                data_get($propertyDetails, 'publicRemarks'),
                data_get($propertyDetails, 'remarks'),
            ]),
            'pictures' => $pictures,
            'formatted_address' => $this->formatAddress($shoot, $propertyDetails),
        ];
    }

    private function resolvePropertyAddress(Shoot $shoot, array $propertyDetails, array $params): ?string
    {
        return $this->firstFilled([
            $params['address'] ?? null,
            $shoot->address,
            data_get($propertyDetails, 'address.street'),
            data_get($propertyDetails, 'address.street_address'),
            data_get($propertyDetails, 'address.streetAddress'),
            data_get($propertyDetails, 'address.line1'),
            data_get($propertyDetails, 'address.address1'),
            data_get($propertyDetails, 'address.formatted'),
            data_get($propertyDetails, 'address'),
        ]);
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }

            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizePrice(?string $price): ?string
    {
        if ($price === null) {
            return null;
        }

        $normalized = preg_replace('/[^\d.]/', '', $price) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    private function buildPictures(Shoot $shoot, array $fileIds = []): array
    {
        $files = $shoot->files;
        $eligibleFiles = $files->whereIn('workflow_stage', [
            ShootFile::STAGE_VERIFIED,
            ShootFile::STAGE_COMPLETED,
        ]);

        if (!empty($fileIds)) {
            $ids = array_values(array_unique(array_map('intval', $fileIds)));
            $selectedEligibleFiles = $eligibleFiles->whereIn('id', $ids);
            $files = $selectedEligibleFiles->isNotEmpty() ? $selectedEligibleFiles : $eligibleFiles;
        } else {
            $files = $eligibleFiles;
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
            return $this->ensureAbsoluteUrl($file->url);
        }

        $path = $file->storage_path ?: $file->path;
        if ($path && Str::startsWith($path, 'http')) {
            return $path;
        }

        if ($path && Storage::disk('public')->exists($path)) {
            return $this->ensureAbsoluteUrl(Storage::disk('public')->url($path));
        }

        if ($path && !Str::startsWith($path, 'http') && !$file->dropbox_path) {
            return $this->ensureAbsoluteUrl(Storage::disk('public')->url($path));
        }

        if ($file->dropbox_path) {
            return $this->dropboxService->getTemporaryLink($file->dropbox_path);
        }

        return null;
    }

    private function ensureAbsoluteUrl(?string $url): ?string
    {
        $candidate = trim((string) $url);
        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $candidate)) {
            return $candidate;
        }

        return url($candidate);
    }
}
