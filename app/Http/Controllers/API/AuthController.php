<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;

class AuthController extends Controller
{
    protected $mailService;
    protected $automationService;

    public function __construct(MailService $mailService, AutomationService $automationService)
    {
        $this->mailService = $mailService;
        $this->automationService = $automationService;
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|max:255|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'phonenumber' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'role' => ['required', Rule::in(['superadmin', 'admin', 'editing_manager', 'client', 'photographer', 'editor', 'salesRep'])],
            'avatar' => 'nullable|url',
            'bio' => 'nullable|string',
        ]);

        // Auto-generate username from email if not provided
        $username = $validated['username'] ?? explode('@', $validated['email'])[0] . '_' . uniqid();

        $user = User::create([
            'name' => $validated['name'],
            'username' => $username,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phonenumber' => $validated['phonenumber'] ?? null,
            'company_name' => $validated['company_name'] ?? null,
            'role' => $validated['role'],
            'avatar' => $validated['avatar'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'account_status' => 'active',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Send account created email
        $resetLink = $this->mailService->generatePasswordResetLink($user);
        $this->mailService->sendAccountCreatedEmail($user, $resetLink);

        $this->automationService->handleEvent('ACCOUNT_CREATED', $this->buildUserContext($user));

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $email = strtolower(trim($request->email));
        Log::info('[Auth] Login attempt', ['email' => $email]);

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            Log::warning('[Auth] Login failed', ['email' => $email]);
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        Log::info('[Auth] Login successful', ['email' => $email, 'user_id' => $user->id]);

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Update the authenticated user's profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phonenumber' => 'nullable|string|max:20',
            'phone_number' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string',
            'terms_accepted' => 'sometimes|boolean',
            'termsAccepted' => 'sometimes|boolean',
        ]);

        // Map phone_number to phonenumber if provided
        if (array_key_exists('phone_number', $validated)) {
            $validated['phonenumber'] = $validated['phone_number'];
            unset($validated['phone_number']);
        }

        $termsAccepted = $validated['terms_accepted'] ?? $validated['termsAccepted'] ?? false;
        unset($validated['terms_accepted'], $validated['termsAccepted']);

        $user->update($validated);

        if ($termsAccepted) {
            $metadata = $user->metadata ?? [];
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?? [];
            }

            if (empty($metadata['terms_accepted_at'])) {
                $metadata['terms_accepted_at'] = now()->toISOString();
                $user->metadata = $metadata;
                $user->save();

                $this->mailService->sendTermsAcceptedEmail($user);
                $this->automationService->handleEvent('TERMS_ACCEPTED', $this->buildUserContext($user));
            }
        }

        Log::info('[Auth] Profile updated', ['user_id' => $user->id, 'fields' => array_keys($validated)]);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Send password reset link (public endpoint)
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        // Always return success for security (don't reveal if email exists)
        if (!$user) {
            return response()->json([
                'message' => 'If your email is registered, you will receive a password reset link.',
            ]);
        }

        // Generate a password reset token
        $token = \Illuminate\Support\Str::random(64);
        
        // Store the token in password_reset_tokens table
        \DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );
        
        // Generate the reset link and send email
        $resetLink = $this->mailService->generatePasswordResetLink($user, $token);
        $this->mailService->sendPasswordResetEmail($user, $resetLink);

        Log::info('[Auth] Password reset link sent', ['email' => $email]);

        return response()->json([
            'message' => 'If your email is registered, you will receive a password reset link.',
        ]);
    }

    /**
     * Reset password using token (public endpoint)
     */
    public function resetPasswordWithToken(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $email = strtolower(trim($validated['email']));
        
        // Find the password reset token
        $resetRecord = \DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'message' => 'Invalid or expired reset link.',
            ], 400);
        }

        // Check if token is valid
        if (!Hash::check($validated['token'], $resetRecord->token)) {
            return response()->json([
                'message' => 'Invalid or expired reset link.',
            ], 400);
        }

        // Check if token is expired (60 minutes)
        $createdAt = \Carbon\Carbon::parse($resetRecord->created_at);
        if ($createdAt->diffInMinutes(now()) > 60) {
            // Delete expired token
            \DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'message' => 'Reset link has expired. Please request a new one.',
            ], 400);
        }

        // Find user and update password
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Delete the used token
        \DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Trigger automation event
        $this->automationService->handleEvent('PASSWORD_RESET', $this->buildUserContext($user));

        Log::info('[Auth] Password reset successful', ['user_id' => $user->id, 'email' => $email]);

        return response()->json([
            'message' => 'Password has been reset successfully. You can now login with your new password.',
        ]);
    }

    private function buildUserContext(User $user): array
    {
        $context = [
            'account_id' => $user->id,
        ];

        $role = strtolower((string) $user->role);
        if ($role === 'client') {
            $context['client'] = $user;
        } elseif ($role === 'photographer') {
            $context['photographer'] = $user;
        } elseif ($role === 'salesrep') {
            $context['rep'] = $user;
        } else {
            $context['client'] = $user;
        }

        return $context;
    }
}
