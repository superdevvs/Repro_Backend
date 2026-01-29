<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MessageChannel;
use App\Models\User;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CakemailController extends Controller
{
    public function __construct(
        private readonly CakemailProvider $cakemail
    ) {}

    /**
     * Test Cakemail connection and get account info
     */
    public function testConnection(): JsonResponse
    {
        $result = $this->cakemail->testConnection();
        
        return response()->json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Get list of senders
     */
    public function getSenders(): JsonResponse
    {
        $senders = $this->cakemail->getSenders();
        
        return response()->json([
            'success' => true,
            'data' => $senders,
        ]);
    }

    /**
     * Get mailing lists
     */
    public function getLists(): JsonResponse
    {
        $lists = $this->cakemail->getLists();
        
        return response()->json([
            'success' => true,
            'data' => $lists,
        ]);
    }

    /**
     * Get transactional email templates
     */
    public function getTemplates(): JsonResponse
    {
        $templates = $this->cakemail->getTransactionalTemplates();
        
        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Send a test email
     */
    public function sendTestEmail(Request $request): JsonResponse
    {
        $request->validate([
            'to' => 'required|email',
            'subject' => 'nullable|string|max:255',
            'message' => 'nullable|string',
        ]);

        $to = $request->input('to');
        $subject = $request->input('subject', 'Test Email from Repro Dashboard');
        $message = $request->input('message', 'This is a test email sent via Cakemail API.');

        $html = $this->buildTestEmailHtml($subject, $message);

        try {
            $channel = new MessageChannel([
                'display_name' => config('mail.from.name', 'R/E Pro Photos'),
                'from_email' => config('services.cakemail.username'),
                'config_json' => [
                    'cakemail_sender_id' => config('services.cakemail.sender_id'),
                    'cakemail_list_id' => config('services.cakemail.list_id'),
                ],
            ]);

            $messageId = $this->cakemail->send($channel, [
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
                'text' => strip_tags($message),
                'tags' => ['test-email', 'api'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully',
                'message_id' => $messageId,
            ]);
        } catch (\Exception $e) {
            Log::error('Cakemail test email failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send email using transactional template
     */
    public function sendTemplateEmail(Request $request): JsonResponse
    {
        $request->validate([
            'template_id' => 'required|integer',
            'to' => 'required|email',
            'variables' => 'nullable|array',
        ]);

        try {
            $contactId = $this->cakemail->sendWithTemplate(
                $request->input('template_id'),
                $request->input('to'),
                $request->input('variables', [])
            );

            return response()->json([
                'success' => true,
                'message' => 'Template email sent successfully',
                'contact_id' => $contactId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync a single contact to Cakemail
     */
    public function syncContact(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'attributes' => 'nullable|array',
            'tags' => 'nullable|array',
            'list_id' => 'nullable|integer',
        ]);

        $listId = $request->input('list_id') ?? config('services.cakemail.list_id');

        if (!$listId) {
            return response()->json([
                'success' => false,
                'error' => 'No list ID configured',
            ], 400);
        }

        $contactId = $this->cakemail->upsertContact(
            (int) $listId,
            $request->input('email'),
            $request->input('attributes', []),
            $request->input('tags', [])
        );

        if ($contactId) {
            return response()->json([
                'success' => true,
                'contact_id' => $contactId,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Failed to sync contact',
        ], 500);
    }

    /**
     * Sync all users of a specific role to Cakemail
     */
    public function syncUsers(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'nullable|string|in:client,photographer,admin,all',
            'list_id' => 'nullable|integer',
        ]);

        $role = $request->input('role', 'client');
        $listId = $request->input('list_id') ?? config('services.cakemail.list_id');

        if (!$listId) {
            return response()->json([
                'success' => false,
                'error' => 'No list ID configured',
            ], 400);
        }

        $query = User::query();
        if ($role !== 'all') {
            $query->where('role', $role);
        }

        $users = $query->get();
        $contacts = $users->map(function ($user) {
            return [
                'email' => $user->email,
                'attributes' => [
                    'first_name' => $user->first_name ?? $user->name ?? '',
                    'last_name' => $user->last_name ?? '',
                    'company' => $user->company_name ?? '',
                    'phone' => $user->phone ?? '',
                    'role' => $user->role ?? 'client',
                    'dashboard_user_id' => (string) $user->id,
                ],
                'tags' => [$user->role ?? 'client', 'dashboard-sync'],
            ];
        })->toArray();

        $result = $this->cakemail->importContacts((int) $listId, $contacts);

        return response()->json([
            'success' => $result['success'],
            'synced_count' => count($contacts),
            'data' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
        ], $result['success'] ? 200 : 500);
    }

    /**
     * Get email delivery logs
     */
    public function getLogs(Request $request): JsonResponse
    {
        $filters = [
            'page' => $request->input('page', 1),
            'per_page' => $request->input('per_page', 50),
        ];

        if ($request->has('filter')) {
            $filters['filter'] = $request->input('filter');
        }

        $logs = $this->cakemail->getLogs($filters);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * Create a transactional email template
     */
    public function createTemplate(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'html' => 'required|string',
            'sender_id' => 'nullable|string',
        ]);

        $templateId = $this->cakemail->createTransactionalTemplate(
            $request->input('name'),
            $request->input('subject'),
            $request->input('html'),
            $request->input('sender_id')
        );

        if ($templateId) {
            return response()->json([
                'success' => true,
                'template_id' => $templateId,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Failed to create template',
        ], 500);
    }

    /**
     * Register webhook for email events
     */
    public function registerWebhook(Request $request): JsonResponse
    {
        $request->validate([
            'event' => 'required|string|in:email.delivered,email.opened,email.clicked,email.bounced,email.unsubscribed',
            'url' => 'required|url',
        ]);

        $webhookId = $this->cakemail->registerWebhook(
            $request->input('event'),
            $request->input('url')
        );

        if ($webhookId) {
            return response()->json([
                'success' => true,
                'webhook_id' => $webhookId,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Failed to register webhook',
        ], 500);
    }

    /**
     * Clear Cakemail token cache (useful after credential change)
     */
    public function clearCache(): JsonResponse
    {
        $this->cakemail->clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Cakemail token cache cleared',
        ]);
    }

    /**
     * Build HTML for test email
     */
    private function buildTestEmailHtml(string $subject, string $message): string
    {
        $now = now()->format('Y-m-d H:i:s T');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px 20px; background: #ffffff; }
        .content h2 { color: #1f2937; margin-top: 0; }
        .content p { color: #4b5563; }
        .info-box { background: #f3f4f6; border-left: 4px solid #2563eb; padding: 15px; margin: 20px 0; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #9ca3af; background: #f9fafb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>R/E Pro Photos</h1>
        </div>
        <div class="content">
            <h2>{$subject}</h2>
            <p>{$message}</p>
            <div class="info-box">
                <strong>Email Details:</strong><br>
                Sent at: {$now}<br>
                Provider: Cakemail API<br>
                Type: Test Email
            </div>
        </div>
        <div class="footer">
            <p>© R/E Pro Photos - Professional Real Estate Photography</p>
            <p>This is a test email from the Cakemail integration.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
