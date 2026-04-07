<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContactSubmissionController extends Controller
{
    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * Store a contact form submission from client portfolio
     * POST /api/public/client/{username}/contact
     */
    public function store(Request $request, string $username)
    {
        $client = $this->resolveClientIdentifier($username);
        if (!$client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        return $this->storeForClient($request, $client);
    }

    /**
     * Store a contact form submission from client portfolio
     * POST /api/public/clients/{client}/contact
     */
    public function storeByClient(Request $request, User $client)
    {
        return $this->storeForClient($request, $client);
    }

    protected function storeForClient(Request $request, User $client)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'message' => 'required|string|max:5000',
        ]);

        try {
            // Create the submission
            $submission = ContactSubmission::create([
                'client_id' => $client->id,
                'sender_name' => $validated['name'],
                'sender_email' => $validated['email'],
                'sender_phone' => $validated['phone'] ?? null,
                'message' => $validated['message'],
                'source' => 'client_portfolio',
            ]);

            // Send notification email to client
            $this->sendContactNotification($client, $submission);

            // Send confirmation email to sender (optional)
            $this->sendSenderConfirmation($submission);

            return response()->json([
                'message' => 'Your message has been sent successfully.',
                'data' => [
                    'id' => $submission->id,
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to submit contact form', [
                'client_id' => $client->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to send your message. Please try again.',
            ], 500);
        }
    }

    protected function resolveClientIdentifier(string $identifier): ?User
    {
        return User::where('username', $identifier)
            ->orWhere('id', $identifier)
            ->first();
    }

    /**
     * Send notification email to the portfolio owner (client)
     */
    protected function sendContactNotification(User $client, ContactSubmission $submission): void
    {
        try {
            $html = view('emails.contact_notification', [
                'client' => $client,
                'submission' => $submission,
            ])->render();

            app(MessagingService::class)->sendEmail([
                'to' => $client->email,
                'subject' => 'New Contact Form Submission - ' . $submission->sender_name,
                'body_html' => $html,
                'body_text' => strip_tags($html),
                'send_source' => 'CONTACT_NOTIFICATION',
                'sender_name' => 'R/E Pro Photos',
            ]);

            Log::info('Contact notification email sent', [
                'client_id' => $client->id,
                'submission_id' => $submission->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send contact notification email', [
                'client_id' => $client->id,
                'submission_id' => $submission->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send confirmation email to the sender
     */
    protected function sendSenderConfirmation(ContactSubmission $submission): void
    {
        try {
            $html = view('emails.contact_confirmation', [
                'submission' => $submission,
                'client' => $submission->client,
            ])->render();

            app(MessagingService::class)->sendEmail([
                'to' => $submission->sender_email,
                'subject' => 'Thank you for contacting us',
                'body_html' => $html,
                'body_text' => strip_tags($html),
                'send_source' => 'CONTACT_CONFIRMATION',
                'sender_name' => 'R/E Pro Photos',
            ]);

            Log::info('Contact confirmation email sent', [
                'submission_id' => $submission->id,
                'sender_email' => $submission->sender_email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send contact confirmation email', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get contact submissions for a client (authenticated)
     * GET /api/contact-submissions
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = ContactSubmission::query();

        // Clients see their own submissions, admins see all
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            $query->where('client_id', $user->id);
        }

        $submissions = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($submissions);
    }

    /**
     * Mark submission as read
     * POST /api/contact-submissions/{submission}/read
     */
    public function markAsRead(Request $request, ContactSubmission $submission)
    {
        $user = $request->user();

        // Only the client owner or admin can mark as read
        if ($submission->client_id !== $user->id && !in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $submission->markAsRead();

        return response()->json([
            'message' => 'Marked as read',
            'data' => $submission
        ]);
    }
}
