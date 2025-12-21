<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage; // To handle file uploads/downloads
use App\Models\User; // Example: Assuming you have a User model
use App\Models\OauthToken;

class DropboxAuthController extends Controller
{
    protected $dropboxApiUrl = 'https://api.dropboxapi.com/2';
    protected $dropboxContentUrl = 'https://content.dropboxapi.com/2';

    // --- Configuration ---
    // Note: These should be in your config/services.php and .env file
    // 'dropbox' => [
    //    'client_id' => env('DROPBOX_CLIENT_ID'),
    //    'client_secret' => env('DROPBOX_CLIENT_SECRET'),
    //    'redirect' => env('DROPBOX_REDIRECT_URI'),
    // ],
    protected $clientId;
    protected $clientSecret;
    protected $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.dropbox.client_id');
        $this->clientSecret = config('services.dropbox.client_secret');
        $this->redirectUri = config('services.dropbox.redirect');
    }

    // ===================================================================
    // == CONFIGURATION
    // ===================================================================

    /**
     * Get Dropbox configuration for frontend (non-sensitive data only)
     */
    public function getConfig()
    {
        $clientId = config('services.dropbox.client_id');
        $redirectUri = config('services.dropbox.redirect');
        
        if (empty($clientId) || $clientId === 'your_dropbox_app_key') {
            return response()->json([
                'success' => false,
                'message' => 'Dropbox Client ID is not configured. Please set DROPBOX_CLIENT_ID in your .env file.',
                'config' => null,
            ], 400);
        }
        
        return response()->json([
            'success' => true,
            'config' => [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
            ],
        ]);
    }

    // ===================================================================
    // == AUTHENTICATION FLOW
    // ===================================================================

    /**
     * Step 1: Redirect the user to Dropbox's authorization page.
     */
    public function connect(Request $request)
    {
        $state = $request->query('debug') ? 'debug' : null;
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'token_access_type' => 'offline', // Important to get a refresh token
            'scope' => 'files.content.write files.content.read account_info.read', // Request necessary permissions
        ];

        if ($state) {
            $params['state'] = $state;
        }

        $authUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query($params);

        return Redirect::to($authUrl);
    }

    /**
     * Step 2: Dropbox redirects back to this method.
     * Exchange the authorization code for an access token.
     */
    public function callback(Request $request)
    {
        if ($request->has('error')) {
            Log::error('Dropbox auth error: ' . $request->input('error_description'));
            return response()->json(['error' => 'Dropbox authorization failed.'], 400);
        }

        $code = $request->input('code');

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post('https://api.dropboxapi.com/oauth2/token', [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->redirectUri,
                ]);

            if ($response->failed()) {
                Log::error('Dropbox token exchange failed:', $response->json());
                return response()->json(['error' => 'Could not retrieve access token.'], 500);
            }

            $tokenData = $response->json();

            // Persist tokens for server-wide Dropbox integration
            OauthToken::updateOrCreate(
                ['provider' => 'dropbox'],
                [
                    'access_token' => $tokenData['access_token'] ?? null,
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds((int)$tokenData['expires_in']) : null,
                ]
            );

            // **IMPORTANT**: Securely store these tokens in your database
            // associated with the authenticated user.
            // Example for the currently logged-in user:
            // $user = auth()->user();
            // $user->update([
            //     'dropbox_account_id' => $tokenData['account_id'],
            //     'dropbox_access_token' => $tokenData['access_token'],
            //     'dropbox_refresh_token' => $tokenData['refresh_token'],
            //     'dropbox_token_expires_at' => now()->addSeconds($tokenData['expires_in']),
            // ]);

            Log::info('Dropbox account linked successfully for account_id: ' . ($tokenData['account_id'] ?? 'unknown'));

            // If state=debug, return tokens as JSON to help set .env locally
            if ($request->input('state') === 'debug' || config('app.env') === 'local') {
                // Mask the access token in logs but return full values in response for setup
                return response()->json([
                    'message' => 'Dropbox OAuth successful. Copy the following into your .env',
                    'env_keys' => [
                        'DROPBOX_CLIENT_ID' => config('services.dropbox.client_id'),
                        'DROPBOX_CLIENT_SECRET' => substr(config('services.dropbox.client_secret'), 0, 4) . '...hidden',
                        'DROPBOX_ACCESS_TOKEN' => $tokenData['access_token'] ?? null,
                        'DROPBOX_REFRESH_TOKEN' => $tokenData['refresh_token'] ?? null,
                    ],
                    'notes' => [
                        'Set DROPBOX_CLIENT_ID and DROPBOX_CLIENT_SECRET from your Dropbox app.',
                        'Paste DROPBOX_REFRESH_TOKEN to enable auto-refresh of access tokens.',
                        'Paste DROPBOX_ACCESS_TOKEN as an initial token; it will refresh automatically when expired.'
                    ]
                ]);
            }

            // Default: redirect user
            return redirect('/dashboard')->with('success', 'Dropbox account connected!');

        } catch (\Exception $e) {
            Log::error('Exception during Dropbox token exchange: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    /**
     * Revoke the user's access token and disconnect their account.
     */
    public function disconnect()
    {
        // **IMPORTANT**: Retrieve the user's access token from your database.
        // $user = auth()->user();
        // $accessToken = $user->dropbox_access_token;
        $accessToken = "USER_ACCESS_TOKEN_FROM_DB"; // Placeholder

        try {
             Http::withToken($accessToken)->post($this->dropboxApiUrl . '/auth/token/revoke');

            // **IMPORTANT**: Clear the tokens from your database after revoking.
            // $user->update([
            //     'dropbox_account_id' => null,
            //     'dropbox_access_token' => null,
            //     'dropbox_refresh_token' => null,
            //     'dropbox_token_expires_at' => null,
            // ]);

            Log::info('Dropbox token revoked for user.');
            return redirect('/settings')->with('success', 'Dropbox account disconnected.');

        } catch (\Exception $e) {
            Log::error('Failed to revoke Dropbox token: ' . $e->getMessage());
            return back()->with('error', 'Could not disconnect Dropbox account.');
        }
    }

    // ===================================================================
    // == USER INFO
    // ===================================================================

    /**
     * Get information about the current user's Dropbox account.
     */
    public function getUserAccount()
    {
        // $accessToken = $this->getValidAccessTokenForUser(); // Your logic to get a valid token
    $accessToken = config('services.dropbox.access_token'); // Use config value

        $response = Http::withToken($accessToken)
            ->withOptions(['verify' => config('app.env') === 'production'])
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody('null')
            ->post($this->dropboxApiUrl . '/users/get_current_account');

        if ($response->successful()) {
            return response()->json($response->json());
        }

        return response()->json(['error' => 'Failed to fetch user account.'], $response->status());
    }


    // ===================================================================
    // == FILE & FOLDER OPERATIONS
    // ===================================================================

    /**
     * List files and folders in a given path.
     * @param string $path The path to list. Empty string for root.
     */
    public function listFiles(Request $request)
    {
        $path = $request->input('path', ''); // Default to root directory

        // $accessToken = $this->getValidAccessTokenForUser();
    $accessToken = config('services.dropbox.access_token'); // Use config value

        $response = Http::withToken($accessToken)
            ->post($this->dropboxApiUrl . '/files/list_folder', [
                'path' => $path === '/' ? '' : $path, // API requires empty string for root
                'recursive' => false,
                'include_media_info' => true,
            ]);

        if ($response->successful()) {
            return response()->json($response->json());
        }

        Log::error('Dropbox listFiles error:', $response->json());
        return response()->json(['error' => 'Could not list files.'], $response->status());
    }

    /**
     * Upload a file to a specified Dropbox path.
     */
    public function uploadFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'path' => 'required|string', // e.g., "/Apps/MyApp/image.jpg"
        ]);

    $accessToken = config('services.dropbox.access_token'); // Use config value

        $fileContent = $request->file('file')->get();
        $dropboxPath = $request->input('path');

        $apiArgs = json_encode([
            'path' => $dropboxPath,
            'mode' => 'add', // or 'overwrite'
            'autorename' => true,
            'mute' => false,
        ]);

        $response = Http::withToken($accessToken)
            ->withBody($fileContent, 'application/octet-stream')
            ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
            ->post($this->dropboxContentUrl . '/files/upload');

        if ($response->successful()) {
            return response()->json($response->json());
        }

        Log::error('Dropbox upload error:', $response->json());
        return response()->json(['error' => 'File upload failed.'], $response->status());
    }
    
    /**
     * Download a file from Dropbox.
     */
    public function downloadFile(Request $request)
    {
        $path = $request->input('path');
        if (!$path) {
            return response()->json(['error' => 'File path is required.'], 400);
        }

    $accessToken = config('services.dropbox.access_token'); // Use config value

        $apiArgs = json_encode(['path' => $path]);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
            ->get($this->dropboxContentUrl . '/files/download');

        if ($response->successful()) {
            $filename = basename($path);
            return response($response->body())
                ->header('Content-Type', $response->header('Content-Type'))
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        }

        Log::error('Dropbox download error:', $response->json());
        return response()->json(['error' => 'Could not download file.'], $response->status());
    }

    /**
     * Delete a file or folder from Dropbox.
     */
    public function deleteFile(Request $request)
    {
        $path = $request->input('path');
        if (!$path) {
            return response()->json(['error' => 'Path is required for deletion.'], 400);
        }

    $accessToken = config('services.dropbox.access_token'); // Use config value

        $response = Http::withToken($accessToken)
            ->post($this->dropboxApiUrl . '/files/delete_v2', [
                'path' => $path
            ]);

        if ($response->successful()) {
            return response()->json($response->json());
        }

        Log::error('Dropbox delete error:', $response->json());
        return response()->json(['error' => 'Could not delete item.'], $response->status());
    }

    // ===================================================================
    // == WEBHOOK HANDLER (Your existing code, slightly cleaned up)
    // ===================================================================

    /**
     * Dropbox Webhook endpoint.
     * Handles verification (GET) and notifications (POST).
     */
    public function webhook(Request $request)
    {
        // 1. Verification step (GET)
        if ($request->isMethod('get') && $request->has('challenge')) {
            return response($request->query('challenge'), 200)
                ->header('Content-Type', 'text/plain')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        // 2. Notification step (POST)
        if ($request->isMethod('post')) {
            $payload = $request->getContent();
            Log::info('Dropbox Webhook Payload:', [$payload]);

            // **Optional**: Verify the request signature for security
            // $signature = $request->header('X-Dropbox-Signature');
            // if (!$this->isValidSignature($signature, $payload)) {
            //     Log::warning('Invalid Dropbox webhook signature received.');
            //     return response()->json(['error' => 'Invalid signature'], 403);
            // }

            $data = json_decode($payload, true);
            if (isset($data['list_folder']['accounts'])) {
                foreach ($data['list_folder']['accounts'] as $accountId) {
                    Log::info("Dropbox change detected for account: " . $accountId);
                    
                    // You should queue a job here to process the changes
                    // to avoid timeout issues and long-running requests.
                    // ProcessDropboxChanges::dispatch($accountId);
                }
            }

            return response()->json(['status' => 'ok']);
        }

        return response()->json(['error' => 'Invalid request'], 400);
    }
}
