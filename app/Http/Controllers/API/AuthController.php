<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Services\MailService;

class AuthController extends Controller
{
    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'phonenumber' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'role' => ['required', Rule::in(['super_admin', 'admin', 'client', 'photographer', 'editor'])],
            'avatar' => 'nullable|url',
            'bio' => 'nullable|string',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
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
}
