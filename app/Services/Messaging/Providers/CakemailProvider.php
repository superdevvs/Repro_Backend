<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CakemailProvider implements EmailProviderInterface
{
    protected string $baseUrl = 'https://api.cakemail.dev';
    protected string $username;
    protected string $password;
    protected ?string $defaultSenderId;
    protected ?int $defaultListId;

    public function __construct()
    {
        $this->username = config('services.cakemail.username', '');
        $this->password = config('services.cakemail.password', '');
        $this->defaultSenderId = config('services.cakemail.sender_id');
        $listId = config('services.cakemail.list_id');
        $this->defaultListId = $listId ? (int) $listId : null;
    }

    /**
     * Send email via Cakemail Email API
     */
    public function send(MessageChannel $channel, array $payload): string
    {
        $token = $this->getAccessToken();

        if (!$token) {
            throw new \RuntimeException('Failed to authenticate with Cakemail API');
        }

        $senderId = $channel->config_json['cakemail_sender_id'] ?? $this->defaultSenderId;
        $listId = $channel->config_json['cakemail_list_id'] ?? $this->defaultListId;

        $contentType = $payload['type'] ?? 'transactional';

        $emailPayload = [
            'sender' => [
                'id' => $senderId,
                'name' => $channel->display_name ?? config('mail.from.name', 'R/E Pro Photos'),
            ],
            'content' => [
                'type' => $contentType,
                'subject' => $payload['subject'] ?? 'Message from Repro HQ',
                'html' => $payload['html'] ?? $payload['body_html'] ?? '',
                'text' => $payload['text'] ?? $payload['body_text'] ?? strip_tags($payload['html'] ?? ''),
                'encoding' => $payload['encoding'] ?? 'utf-8',
            ],
            'email' => $payload['to'],
            'tracking' => [
                'opens' => true,
                'clicks_html' => true,
                'clicks_text' => true,
            ],
        ];

        // Cakemail requires list_id on send requests
        if ($listId) {
            $emailPayload['list_id'] = (int) $listId;
        }

        // Add tags if provided
        if (!empty($payload['tags'])) {
            $emailPayload['tags'] = $payload['tags'];
        }

        // Add attachments if provided
        if (!empty($payload['attachments'])) {
            $emailPayload['attachment'] = array_map(function ($attachment) {
                return [
                    'filename' => $attachment['filename'] ?? 'attachment',
                    'content' => base64_encode($attachment['content'] ?? ''),
                    'content_type' => $attachment['content_type'] ?? 'application/octet-stream',
                ];
            }, $payload['attachments']);
        }

        // Add reply-to if provided
        if (!empty($payload['reply_to'])) {
            $emailPayload['additional_headers'] = [
                ['name' => 'Reply-To', 'value' => $payload['reply_to']],
            ];
        }

        Log::info('Cakemail: Sending email', [
            'to' => $payload['to'],
            'subject' => $emailPayload['content']['subject'],
            'sender_id' => $senderId,
        ]);

        $response = Http::withoutVerifying()
            ->withToken($token)
            ->timeout(30)
            ->post("{$this->baseUrl}/v2/emails", $emailPayload);

        if ($response->failed()) {
            $responseJson = $response->json() ?? [];
            Log::error('Cakemail email send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'response' => $responseJson,
                'to' => $payload['to'],
            ]);

            $errorMessage = $responseJson['detail'] ?? $responseJson['message'] ?? $response->body();
            if (is_array($errorMessage)) {
                $errorMessage = json_encode($errorMessage);
            }
            throw new \RuntimeException('Failed to send email via Cakemail: ' . $errorMessage);
        }

        $responseData = $response->json();
        $messageId = $responseData['data']['id'] 
            ?? $responseData['id'] 
            ?? Str::uuid()->toString();

        Log::info('Cakemail: Email sent successfully', [
            'message_id' => $messageId,
            'to' => $payload['to'],
            'status' => $responseData['data']['status'] ?? 'queued',
        ]);

        return (string) $messageId;
    }

    /**
     * Schedule email for later delivery (Cakemail doesn't have native scheduling, so we send immediately)
     */
    public function schedule(MessageChannel $channel, array $payload): string
    {
        // Cakemail Email API doesn't support native scheduling
        // For scheduled emails, use Laravel's queue system
        return $this->send($channel, $payload);
    }

    /**
     * Send email using a transactional template
     */
    public function sendWithTemplate(int $templateId, string $toEmail, array $customAttributes = []): string
    {
        $token = $this->getAccessToken();

        if (!$token) {
            throw new \RuntimeException('Failed to authenticate with Cakemail API');
        }

        $payload = [
            'email' => $toEmail,
        ];

        if (!empty($customAttributes)) {
            $payload['custom_attributes'] = array_map(function ($key, $value) {
                return ['name' => $key, 'value' => (string) $value];
            }, array_keys($customAttributes), array_values($customAttributes));
        }

        $response = Http::withoutVerifying()
            ->withToken($token)
            ->timeout(30)
            ->post("{$this->baseUrl}/transactional-email-templates/{$templateId}/send", $payload);

        if ($response->failed()) {
            Log::error('Cakemail template send failed', [
                'template_id' => $templateId,
                'to' => $toEmail,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to send template email: ' . $response->body());
        }

        $responseData = $response->json();
        return $responseData['data']['contact_id'] ?? Str::uuid()->toString();
    }

    /**
     * Get OAuth2 access token with caching
     */
    public function getAccessToken(): ?string
    {
        $cacheKey = 'cakemail_token_' . md5($this->username);

        // Check cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (empty($this->username) || empty($this->password)) {
            Log::error('Cakemail credentials not configured');
            return null;
        }

        try {
            $response = Http::withoutVerifying()
                ->asForm()
                ->timeout(30)
                ->post("{$this->baseUrl}/token", [
                    'grant_type' => 'password',
                    'username' => $this->username,
                    'password' => $this->password,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['access_token'] ?? null;
                $expiresIn = $data['expires_in'] ?? 3600;

                if ($token) {
                    // Cache token for slightly less than expiry time
                    Cache::put($cacheKey, $token, now()->addSeconds($expiresIn - 300));

                    // Store refresh token if available
                    if (!empty($data['refresh_token'])) {
                        Cache::put($cacheKey . '_refresh', $data['refresh_token'], now()->addDays(30));
                    }

                    // Store account IDs
                    if (!empty($data['accounts'])) {
                        Cache::put($cacheKey . '_accounts', $data['accounts'], now()->addDays(30));
                    }

                    Log::info('Cakemail: Access token obtained', [
                        'expires_in' => $expiresIn,
                        'accounts' => $data['accounts'] ?? [],
                    ]);

                    return $token;
                }
            }

            Log::error('Cakemail token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Cakemail: Failed to get access token', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Refresh the access token using refresh token
     */
    public function refreshAccessToken(): ?string
    {
        $cacheKey = 'cakemail_token_' . md5($this->username);
        $refreshToken = Cache::get($cacheKey . '_refresh');

        if (!$refreshToken) {
            return $this->getAccessToken();
        }

        try {
            $response = Http::withoutVerifying()
                ->asForm()
                ->timeout(30)
                ->post("{$this->baseUrl}/token", [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['access_token'] ?? null;
                $expiresIn = $data['expires_in'] ?? 3600;

                if ($token) {
                    Cache::put($cacheKey, $token, now()->addSeconds($expiresIn - 300));

                    if (!empty($data['refresh_token'])) {
                        Cache::put($cacheKey . '_refresh', $data['refresh_token'], now()->addDays(30));
                    }

                    return $token;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Cakemail: Failed to refresh token, getting new one', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to getting new token
        return $this->getAccessToken();
    }

    /**
     * Get list of senders from Cakemail
     */
    public function getSenders(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return [];
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->get("{$this->baseUrl}/brands/default/senders");

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Cakemail: Failed to get senders', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Create a new sender in Cakemail
     */
    public function createSender(string $email, string $name): ?int
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return null;
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->post("{$this->baseUrl}/brands/default/senders", [
                    'email' => $email,
                    'name' => $name,
                    'language' => 'en_US',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $senderId = $data['data']['id'] ?? null;
                Log::info('Cakemail: Sender created', [
                    'email' => $email,
                    'sender_id' => $senderId,
                    'confirmed' => $data['data']['confirmed'] ?? false,
                ]);
                return $senderId;
            }

            Log::error('Cakemail: Failed to create sender', [
                'email' => $email,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Cakemail: Sender creation error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get list of mailing lists from Cakemail
     */
    public function getLists(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return [];
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->get("{$this->baseUrl}/lists");

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Cakemail: Failed to get lists', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Add or update a contact in a list
     */
    public function upsertContact(int $listId, string $email, array $attributes = [], array $tags = []): ?int
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return null;
        }

        $payload = [
            'email' => $email,
        ];

        if (!empty($attributes)) {
            $payload['custom_attributes'] = array_map(function ($key, $value) {
                return ['name' => $key, 'value' => (string) $value];
            }, array_keys($attributes), array_values($attributes));
        }

        if (!empty($tags)) {
            $payload['tags'] = $tags;
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->post("{$this->baseUrl}/lists/{$listId}/contacts", $payload);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Cakemail: Contact upserted', [
                    'email' => $email,
                    'list_id' => $listId,
                    'contact_id' => $data['data']['id'] ?? null,
                ]);
                return $data['data']['id'] ?? null;
            }

            Log::warning('Cakemail: Failed to upsert contact', [
                'email' => $email,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Cakemail: Contact upsert error', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Import multiple contacts to a list
     */
    public function importContacts(int $listId, array $contacts): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return ['success' => false, 'error' => 'Not authenticated'];
        }

        $payload = [
            'contacts' => array_map(function ($contact) {
                $item = ['email' => $contact['email']];
                
                if (!empty($contact['attributes'])) {
                    $item['custom_attributes'] = array_map(function ($key, $value) {
                        return ['name' => $key, 'value' => (string) $value];
                    }, array_keys($contact['attributes']), array_values($contact['attributes']));
                }

                if (!empty($contact['tags'])) {
                    $item['tags'] = $contact['tags'];
                }

                return $item;
            }, $contacts),
        ];

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(60)
                ->post("{$this->baseUrl}/lists/{$listId}/import-contacts", $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get transactional email templates
     */
    public function getTransactionalTemplates(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return [];
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->get("{$this->baseUrl}/transactional-email-templates");

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Cakemail: Failed to get templates', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Create a transactional email template
     */
    public function createTransactionalTemplate(string $name, string $subject, string $html, ?string $senderId = null): ?int
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return null;
        }

        $payload = [
            'name' => $name,
            'sender' => ['id' => $senderId ?? $this->defaultSenderId],
            'content' => [
                'type' => 'html',
                'subject' => $subject,
                'html' => $html,
            ],
        ];

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->post("{$this->baseUrl}/transactional-email-templates", $payload);

            if ($response->successful()) {
                return $response->json()['data']['id'] ?? null;
            }

            Log::error('Cakemail: Failed to create template', [
                'name' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Cakemail: Template creation error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get email logs/analytics
     */
    public function getLogs(array $filters = []): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return [];
        }

        $params = [
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 50,
        ];

        if (!empty($filters['filter'])) {
            $params['filter'] = $filters['filter'];
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->get("{$this->baseUrl}/logs", $params);

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Cakemail: Failed to get logs', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Register a webhook for email events
     */
    public function registerWebhook(string $event, string $url): ?string
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return null;
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->post("{$this->baseUrl}/webhooks", [
                    'event' => $event,
                    'url' => $url,
                ]);

            if ($response->successful()) {
                return $response->json()['data']['id'] ?? null;
            }

            Log::warning('Cakemail: Failed to register webhook', [
                'event' => $event,
                'url' => $url,
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::error('Cakemail: Webhook registration error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Test connection and get account info
     */
    public function testConnection(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return [
                'success' => false,
                'error' => 'Failed to authenticate. Check your credentials.',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->get("{$this->baseUrl}/accounts/self");

            if ($response->successful()) {
                $senders = $this->getSenders();
                $lists = $this->getLists();

                return [
                    'success' => true,
                    'account' => $response->json()['data'] ?? [],
                    'senders' => $senders,
                    'lists' => $lists,
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to get account info: ' . $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clear cached tokens (useful for testing or credential changes)
     */
    public function clearCache(): void
    {
        $cacheKey = 'cakemail_token_' . md5($this->username);
        Cache::forget($cacheKey);
        Cache::forget($cacheKey . '_refresh');
        Cache::forget($cacheKey . '_accounts');
    }
}
