<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ServiceGroup;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\DashboardOnboardingService;
use App\Services\Users\EmailHealthService;
use App\Services\Users\AccountCreatedNotificationService;
use App\Services\Users\PhotographerAddressPolicy;
use App\Services\Users\TwoFactorAuthenticationService;
use App\Services\Legal\LegalDocumentService;

class AuthController extends Controller
{
    protected $mailService;
    protected $automationService;
    protected $emailHealthService;
    protected $twoFactorAuthentication;

    public function __construct(
        MailService $mailService,
        AutomationService $automationService,
        EmailHealthService $emailHealthService,
        TwoFactorAuthenticationService $twoFactorAuthentication
    )
    {
        $this->mailService = $mailService;
        $this->automationService = $automationService;
        $this->emailHealthService = $emailHealthService;
        $this->twoFactorAuthentication = $twoFactorAuthentication;
    }

    public function register(Request $request)
    {
        \App\Support\TaxDocumentMetadata::assertWritable($request->all());
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|max:255|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'phonenumber' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'avatar' => 'nullable|url',
            'bio' => 'nullable|string',
            'email_warning_override' => 'sometimes|boolean',
        ]);

        $emailHealthMutation = $this->resolveEmailHealthMutation(
            (string) $validated['email'],
            $request->boolean('email_warning_override')
        );

        if ($emailHealthMutation['response']) {
            return $emailHealthMutation['response'];
        }

        // Auto-generate username from email if not provided
        $normalizedEmail = $emailHealthMutation['attributes']['email'] ?? strtolower(trim((string) $validated['email']));
        $username = $validated['username'] ?? explode('@', $normalizedEmail)[0] . '_' . uniqid();

        $user = User::create([
            'name' => $validated['name'],
            'username' => $username,
            'email' => $normalizedEmail,
            'password' => Hash::make($validated['password']),
            'phonenumber' => $validated['phonenumber'] ?? null,
            'company_name' => $validated['company_name'] ?? null,
            'role' => 'client',
            'avatar' => $validated['avatar'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'account_status' => 'active',
            'metadata' => app(DashboardOnboardingService::class)->applyEligibility([], 'client', 'registration'),
            ...$emailHealthMutation['attributes'],
        ]);

        if ($user->role === 'client') {
            $defaultServiceGroup = ServiceGroup::getDefaultGroup();
            if ($defaultServiceGroup) {
                $user->serviceGroups()->sync([$defaultServiceGroup->id]);
            }
        }

        $this->recordUserActivity(
            $user,
            'account_created',
            'Account created',
            'A new client registered through the public signup form.',
            [
                'role' => $user->role,
                'email' => $user->email,
            ]
        );

        if ($emailHealthMutation['warning_override']) {
            $this->recordUserActivity(
                $user,
                'email_warning_override',
                'Email warning overridden',
                'The client confirmed saving an email that matched a likely typo pattern during registration.',
                [
                    'email' => $user->email,
                    'suggested_correction' => $emailHealthMutation['analysis']['suggested_correction'] ?? null,
                    'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                ]
            );
        }

        $newToken = $user->createToken('auth_token');
        $token = $newToken->plainTextToken;
        $this->recordUserActivity(
            $user,
            'login',
            'Signed in',
            'A new dashboard session was created after registration.',
            [
                'token_id' => $newToken->accessToken->getKey(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        $notificationDelivery = app(AccountCreatedNotificationService::class)->dispatch($user, [
            'actor' => $user,
            'issued_context' => 'registration',
        ]);
        unset($notificationDelivery['links']);
        $deliveryFailed = collect([$notificationDelivery['email']['account_created'], $notificationDelivery['email']['verification'], $notificationDelivery['sms']])
            ->contains(fn (array $channel) => $channel['attempted'] && !$channel['sent']);

        return response()->json([
            'message' => $deliveryFailed ? 'User registered, but one or more notifications failed.' : 'User registered successfully.',
            'user' => $user->fresh(),
            'token' => $token,
            'notification_delivery' => $notificationDelivery,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'two_factor_code' => 'nullable|string|max:32',
        ]);

        $email = strtolower(trim($request->email));
        Log::info('[Auth] Login attempt', ['email' => $email]);

        $user = User::withTrashed()->where('email', $email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            Log::warning('[Auth] Login failed', ['email' => $email]);
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        if (!$user->isAccountEligibleForAuthentication()) {
            $user->tokens()->delete();

            Log::warning('[Auth] Login blocked for inactive account', [
                'email' => $email,
                'user_id' => $user->id,
                'account_status' => $user->account_status,
                'deleted_at' => optional($user->deleted_at)->toIso8601String(),
            ]);

            return response()->json([
                'message' => 'This account is no longer active.',
            ], 403);
        }

        if ($user->password_reset_required) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Reset your password before signing in to this restored account.',
                'password_reset_required' => true,
            ], 403);
        }

        if ($this->twoFactorAuthentication->enabled($user)) {
            $twoFactorCode = (string) $request->input('two_factor_code', '');
            if ($twoFactorCode === '') {
                return response()->json([
                    'message' => 'Enter the code from your authenticator app.',
                    'two_factor_required' => true,
                ], 202);
            }

            if (!$this->twoFactorAuthentication->verifyUserCode($user, $twoFactorCode)) {
                throw ValidationException::withMessages([
                    'two_factor_code' => ['The authentication or recovery code is invalid.'],
                ]);
            }
        }

        $newToken = $user->createToken('auth_token');
        $token = $newToken->plainTextToken;

        // Re-evaluate dashboard onboarding eligibility on login so existing users
        // are (re)enrolled when a role's onboarding version is bumped, without
        // requiring the seed command. No-op for non-onboarded roles or users
        // already at the current version (see DashboardOnboardingService).
        $existingMetadata = $user->metadata ?? [];
        $reevaluatedMetadata = app(DashboardOnboardingService::class)->applyEligibility(
            $existingMetadata,
            (string) $user->role,
            'login'
        );
        if ($reevaluatedMetadata !== $existingMetadata) {
            $user->metadata = $reevaluatedMetadata;
            $user->save();
        }

        Log::info('[Auth] Login successful', ['email' => $email, 'user_id' => $user->id]);
        $this->recordUserActivity(
            $user,
            'login',
            'Signed in',
            'A dashboard session was created.',
            [
                'token_id' => $newToken->accessToken->getKey(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'two_factor_verified' => $this->twoFactorAuthentication->enabled($user),
            ]
        );

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        $tokenId = $currentToken?->getKey();
        $currentToken?->delete();
        $this->recordUserActivity(
            $user,
            'logout',
            'Signed out',
            'The current dashboard session was ended.',
            [
                'token_id' => $tokenId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Return the authenticated user, re-evaluating onboarding eligibility first.
     *
     * For onboarded roles only, this re-runs the eligibility check so that a
     * backend VERSION_* bump re-triggers onboarding for already-logged-in users
     * on their next fetch. Persistence happens only when the metadata actually
     * changes (steady-state requests do zero writes), and the whole thing is
     * guarded so an eligibility/telemetry hiccup never breaks /api/user.
     */
    public function currentUser(Request $request, DashboardOnboardingService $onboarding)
    {
        $user = $request->user();

        if ($user && $onboarding->isOnboardedRole($user->role)) {
            try {
                $onboarding->refreshEligibilityForUser($user);
            } catch (\Throwable $e) {
                Log::warning('Failed to refresh onboarding eligibility on user fetch.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json($this->presentAuthenticatedUser($user));
    }

    /**
     * Update the authenticated user's profile
     */
    public function updateProfile(Request $request)
    {
        \App\Support\TaxDocumentMetadata::assertWritable($request->all());
        $user = $request->user();

        if ($request->has('email')) {
            $request->merge([
                'email' => strtolower(trim((string) $request->input('email'))),
            ]);
        }

        // Build role-aware onboarding validation rules for all onboarded roles.
        // 'client' keeps its legacy key for backward compatibility.
        $onboardingKeys = [
            'clientDashboardOnboarding',
            'photographerDashboardOnboarding',
            'salesRepDashboardOnboarding',
            'editingManagerDashboardOnboarding',
            'editorDashboardOnboarding',
        ];

        $onboardingRules = [];
        foreach ($onboardingKeys as $onboardingKey) {
            $onboardingRules["preferences.{$onboardingKey}"] = 'nullable|array';
            $onboardingRules["preferences.{$onboardingKey}.eligible"] = 'nullable|boolean';
            $onboardingRules["preferences.{$onboardingKey}.version"] = 'nullable|integer|min:1|max:100';
            $onboardingRules["preferences.{$onboardingKey}.createdAt"] = 'nullable|string|max:100';
            $onboardingRules["preferences.{$onboardingKey}.startedAt"] = 'nullable|string|max:100';
            $onboardingRules["preferences.{$onboardingKey}.completedAt"] = 'nullable|string|max:100';
            $onboardingRules["preferences.{$onboardingKey}.dismissedAt"] = 'nullable|string|max:100';
            $onboardingRules["preferences.{$onboardingKey}.lastStep"] = 'nullable|integer|min:0|max:100';
            $onboardingRules["preferences.{$onboardingKey}.source"] = 'nullable|string|max:100';
            $onboardingRules["preferences.{$onboardingKey}.replayCount"] = 'nullable|integer|min:0|max:100';
        }

        $validated = $request->validate(array_merge([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phonenumber' => 'nullable|string|max:20',
            'phone_number' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string',
            'about' => 'nullable|string',
            'timezone' => 'nullable|string|timezone',
            'facebook_url' => 'nullable|url|max:500',
            'twitter_url' => 'nullable|url|max:500',
            'linkedin_url' => 'nullable|url|max:500',
            'pinterest_url' => 'nullable|url|max:500',
            'terms_accepted' => 'sometimes|boolean',
            'termsAccepted' => 'sometimes|boolean',
            'travel_range' => 'nullable|integer|min:0|max:500',
            'travel_range_unit' => 'nullable|string|in:miles,km',
            'specialties' => 'sometimes|array|max:100',
            'specialties.*' => 'string|max:255',
            'property_types' => 'sometimes|array|max:100',
            'property_types.*' => 'string|max:100',
            // A photographer's own default HDR bracket size. This only seeds a new
            // bracket-capable shoot-service assignment; changing it later never rewrites
            // an assignment that already recorded its own size.
            'default_bracket_mode' => 'nullable|integer|in:3,5',
            'current_password' => 'nullable|string',
            'new_password' => 'nullable|string|min:8|confirmed',
            'new_password_confirmation' => 'nullable|string',
            'preferences' => 'sometimes|array',
            'preferences.preferredPhotographer' => 'nullable|string|max:255',
            'preferences.notificationEmail' => 'nullable|boolean',
            'preferences.notificationSMS' => 'nullable|boolean',
            'preferences.portfolioWebsite' => 'nullable|url|max:500',
            'preferences.weeklyInvoice' => 'nullable|boolean',
            'preferences.showEditingNotes' => 'nullable|boolean',
            'preferences.emailNotifications' => 'nullable|boolean',
            'preferences.department' => 'nullable|string|max:255',
            'preferences.uiDensity' => 'nullable|string|in:default,compact',
            'preferences.notifications' => 'nullable|array',
            'preferences.notifications.shootReminders' => 'nullable|boolean',
            'preferences.notifications.paymentReminders' => 'nullable|boolean',
            'preferences.notifications.weeklySummaries' => 'nullable|boolean',
            'preferences.marketingEmails' => 'nullable|boolean',
            'preferences.notificationSettings' => 'nullable|array',
            'preferences.notificationSettings.email' => 'nullable|boolean',
            'preferences.notificationSettings.sms' => 'nullable|boolean',
            'preferences.notificationSettings.push' => 'nullable|boolean',
            'preferences.notificationSettings.marketing' => 'nullable|boolean',
            'email_warning_override' => 'sometimes|boolean',
        ], $onboardingRules));

        $incomingEmail = $validated['email'] ?? null;
        $currentEmail = strtolower((string) $user->email);
        $emailChanged = is_string($incomingEmail) && $incomingEmail !== $currentEmail;
        $passwordChanged = !empty($validated['new_password']);
        $previousEmail = $currentEmail;
        $previousEmailStatus = strtolower((string) ($user->email_status ?? ''));

        if (($emailChanged || $passwordChanged) && !Hash::check((string) ($validated['current_password'] ?? ''), (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $emailHealthMutation = null;
        if ($emailChanged) {
            if ($this->shouldRequireEmailVerificationForRole($user->role)) {
                $emailHealthMutation = $this->resolveEmailHealthMutation(
                    (string) $incomingEmail,
                    $request->boolean('email_warning_override')
                );

                if ($emailHealthMutation['response']) {
                    return $emailHealthMutation['response'];
                }

                $validated = array_merge($validated, $emailHealthMutation['attributes']);
                $validated['email'] = $emailHealthMutation['attributes']['email'];
            } else {
                $validated = array_merge($validated, $this->clearEmailHealthAttributes());
                $validated['email'] = strtolower(trim((string) $incomingEmail));
            }
        }

        // Map phone_number to phonenumber if provided
        if (array_key_exists('phone_number', $validated)) {
            $validated['phonenumber'] = $validated['phone_number'];
            unset($validated['phone_number']);
        }

        $termsAccepted = $validated['terms_accepted'] ?? $validated['termsAccepted'] ?? false;
        unset($validated['terms_accepted'], $validated['termsAccepted']);

        $metadata = $user->metadata ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }

        // Merge allowed preference updates without touching unrelated metadata.
        if (array_key_exists('preferences', $validated) && is_array($validated['preferences'])) {
            $existingPreferences = is_array($metadata['preferences'] ?? null) ? $metadata['preferences'] : [];
            $metadata['preferences'] = array_replace_recursive($existingPreferences, $validated['preferences']);
            unset($validated['preferences']);
        }

        // Maintain existing metadata keys for currently-shipped photographer settings.
        if (array_key_exists('travel_range', $validated)) {
            $metadata['travel_range'] = $validated['travel_range'];
            unset($validated['travel_range']);
        }
        if (array_key_exists('travel_range_unit', $validated)) {
            $metadata['travel_range_unit'] = $validated['travel_range_unit'];
            unset($validated['travel_range_unit']);
        }
        if (array_key_exists('specialties', $validated)) {
            $metadata['specialties'] = array_values(array_unique($validated['specialties']));
            unset($validated['specialties']);
        }
        if (array_key_exists('property_types', $validated)) {
            $metadata['property_types'] = array_values(array_unique($validated['property_types']));
            unset($validated['property_types']);
        }

        if (array_key_exists('about', $validated)) {
            $metadata['about'] = $validated['about'];
            unset($validated['about']);
        }

        if ($passwordChanged) {
            $validated['password'] = $validated['new_password'];
            $validated['password_changed_at'] = now();
        }
        unset($validated['current_password'], $validated['new_password'], $validated['new_password_confirmation']);
        unset($validated['email_warning_override']);

        $addressChangeQueued = false;
        $addressPolicy = app(PhotographerAddressPolicy::class);
        if ($addressPolicy->isPhotographer($user) && $this->profileContainsAddressFields($validated)) {
            $queued = $addressPolicy->queueSelfServiceChange($user, $validated);
            $addressChangeQueued = $queued['changed'];
            unset($validated['address'], $validated['city'], $validated['state'], $validated['zip']);
        }

        $user->fill($validated);
        $user->metadata = $metadata;
        $reauthRequired = $emailChanged || $passwordChanged;
        DB::transaction(function () use ($user, $reauthRequired, $passwordChanged, $previousEmail): void {
            $user->save();
            if ($reauthRequired) {
                // Credential persistence and direct token deletion are one
                // atomic security decision. Never use the model's best-effort
                // revocation helper for a password or email change.
                $user->tokens()->delete();
            }
            if ($passwordChanged) {
                DB::table('password_reset_tokens')
                    ->whereIn('email', array_values(array_unique([
                        $previousEmail,
                        strtolower((string) $user->email),
                    ])))
                    ->delete();
            }
        });
        $savedChanges = collect(array_keys($user->getChanges()))
            ->reject(fn (string $field) => in_array($field, ['password', 'remember_token', 'updated_at'], true))
            ->values()
            ->all();

        if ($passwordChanged) {
            $this->recordUserActivity(
                $user,
                'password_changed',
                'Password changed',
                'The account password was changed and all dashboard sessions were signed out.',
                [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]
            );
        } elseif ($savedChanges !== []) {
            $this->recordUserActivity(
                $user,
                'profile_updated',
                'Profile updated',
                'Account profile or preferences were updated.',
                [
                    'fields' => $savedChanges,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]
            );
        }

        if ($emailChanged && $emailHealthMutation && $emailHealthMutation['warning_override']) {
            $this->recordUserActivity(
                $user,
                'email_warning_override',
                'Email warning overridden',
                'The client confirmed keeping a likely typo email address while updating their profile.',
                [
                    'email' => $user->email,
                    'suggested_correction' => $emailHealthMutation['analysis']['suggested_correction'] ?? null,
                    'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                ]
            );
        }

        if ($emailChanged && $this->shouldRequireEmailVerificationForRole($user->role)) {
            $verificationSent = false;

            try {
                if ($this->mailService->sendClientEmailVerificationEmail($user, [
                    'issued_context' => 'email_change',
                    'issued_by' => $user->id,
                ])) {
                    $this->emailHealthService->markVerificationSent($user);
                    $verificationSent = true;
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to send account email verification email after self-service profile update', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $exception->getMessage(),
                ]);
            }

            if ($verificationSent) {
                $this->recordUserActivity(
                    $user,
                    'email_verification_requested',
                    'Email verification sent',
                    'A new verification email was sent after the account email address changed.',
                    [
                        'email' => $user->email,
                        'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                    ]
                );
            }

            if ($previousEmailStatus === EmailHealthService::STATUS_BOUNCED && $previousEmail !== strtolower((string) $user->email)) {
                $this->recordUserActivity(
                    $user,
                    'email_corrected_after_bounce',
                    'Bounced email corrected',
                    sprintf('Client email changed from %s to %s after a bounce warning.', $previousEmail, strtolower((string) $user->email)),
                    [
                        'previous_email' => $previousEmail,
                        'updated_email' => strtolower((string) $user->email),
                        'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
                    ]
                );
            }
        }

        if ($termsAccepted) {
            $metadata = $user->metadata ?? [];
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?? [];
            }

            $metadata['first_use_legal_agreement_required'] = false;

            if (empty($metadata['terms_accepted_at'])) {
                $metadata['terms_accepted_at'] = now()->toISOString();

                $this->mailService->sendTermsAcceptedEmail($user);
                $this->automationService->handleEvent('TERMS_ACCEPTED', $this->buildUserContext($user));
            }

            $user->metadata = $metadata;
            $user->save();
        }

        Log::info('[Auth] Profile updated', ['user_id' => $user->id, 'fields' => array_keys($validated)]);

        $message = $reauthRequired
            ? 'Profile updated successfully. Please sign in again to continue.'
            : 'Profile updated successfully';
        if ($addressChangeQueued && !$reauthRequired) {
            $message = 'Profile updated. Your address change was submitted for admin approval and will replace the approved address after review.';
        }

        return response()->json([
            'message' => $message,
            'reauth_required' => $reauthRequired,
            'address_change_pending' => $addressChangeQueued,
            'user' => $this->presentAuthenticatedUser($user->fresh()),
        ]);
    }

    public function resendEmailVerification(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!$this->shouldRequireEmailVerificationForRole($user->role)) {
            return response()->json([
                'sent' => false,
                'message' => 'Verification emails are not required for this account role.',
                'user' => $user->fresh(),
            ], 422);
        }

        if (strtolower((string) ($user->email_status ?? '')) === EmailHealthService::STATUS_VERIFIED) {
            return response()->json([
                'sent' => false,
                'message' => 'This email address is already verified.',
                'user' => $this->presentAuthenticatedUser($user->fresh()),
            ], 422);
        }

        try {
            $sent = $this->mailService->sendClientEmailVerificationEmail($user, [
                'issued_context' => 'dashboard_resend',
                'issued_by' => $user->id,
                'throw_on_failure' => true,
            ]);
        } catch (\Throwable $sendException) {
            return response()->json([
                'sent' => false,
                'message' => 'Unable to send a verification email: ' . $sendException->getMessage(),
            ], 422);
        }

        if (!$sent) {
            return response()->json([
                'sent' => false,
                'message' => 'Unable to send a verification email right now. Please try again.',
            ], 422);
        }

        $this->emailHealthService->markVerificationSent($user);
        $this->recordUserActivity(
            $user,
            'email_verification_requested',
            'Email verification sent',
            'A new verification email was requested from the dashboard.',
            [
                'email' => $user->email,
                'sales_rep_id' => $this->emailHealthService->extractSalesRepId($user),
            ]
        );

        $freshUser = $user->fresh();

        return response()->json([
            'sent' => true,
            'email' => $freshUser->email,
            'message' => 'Verification email sent. Check your inbox to verify your address.',
            'user' => $this->presentAuthenticatedUser($freshUser),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function profileContainsAddressFields(array $validated): bool
    {
        foreach (['address', 'city', 'state', 'zip'] as $field) {
            if (array_key_exists($field, $validated)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentAuthenticatedUser(User $user): array
    {
        $payload = $user->toArray();
        $payload['zipcode'] = $payload['zip'] ?? $user->zip;
        $payload['phone'] = $payload['phonenumber'] ?? $user->phonenumber;
        $payload['email_health'] = $user->email_health;
        $payload['legal_status'] = app(LegalDocumentService::class)->statusFor($user);

        return app(PhotographerAddressPolicy::class)->presentSubjectForViewer($payload, $user, $user);
    }

    /**
     * Upload or replace the authenticated user's tax document metadata.
     */
    public function uploadTaxDocument(Request $request)
    {
        return app(\App\Http\Controllers\API\TaxDocumentController::class)->store($request);
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
        $sent = $this->mailService->sendPasswordResetEmail($user, $resetLink);

        if ($sent) {
            Log::info('[Auth] Password reset link sent successfully', ['email' => $email]);
        } else {
            Log::error('[Auth] Failed to send password reset email', ['email' => $email]);
        }

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

        DB::transaction(function () use ($user, $validated, $email): void {
            $user->password = $validated['password'];
            $user->password_changed_at = now();
            $user->password_reset_required = false;
            $user->save();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        });

        $this->recordUserActivity(
            $user,
            'password_reset',
            'Password reset',
            'The account password was reset using an emailed recovery link. All dashboard sessions were signed out.',
            [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

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
            'account' => $user,
        ];

        $role = strtolower(str_replace(['-', '_', ' '], '', (string) $user->role));
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

    protected function resolveEmailHealthMutation(string $email, bool $warningOverride = false): array
    {
        $analysis = $this->emailHealthService->analyzeForSave($email);

        if (!$analysis['valid'] || (($analysis['requires_confirmation'] ?? false) && !$warningOverride)) {
            return [
                'attributes' => [],
                'analysis' => $analysis,
                'warning_override' => $warningOverride,
                'response' => $this->buildEmailHealthValidationResponse($email, $analysis),
            ];
        }

        return [
            'attributes' => $this->emailHealthService->buildAttributesForSave($email, $analysis),
            'analysis' => $analysis,
            'warning_override' => $warningOverride,
            'response' => null,
        ];
    }

    protected function buildEmailHealthValidationResponse(string $email, array $analysis)
    {
        return response()->json([
            'message' => $analysis['error_message'] ?? $analysis['warning_message'] ?? 'Email validation failed.',
            'errors' => [
                'email' => [
                    $analysis['error_message'] ?? $analysis['warning_message'] ?? 'Email validation failed.',
                ],
            ],
            'email_health' => [
                'status' => $analysis['status'] ?? null,
                'warning_code' => $analysis['warning_code'] ?? null,
                'warning_message' => $analysis['warning_message'] ?? null,
                'suggested_correction' => $analysis['suggested_correction'] ?? null,
                'requires_confirmation' => (bool) ($analysis['requires_confirmation'] ?? false),
                'entered_email' => strtolower(trim($email)),
            ],
        ], 422);
    }

    protected function shouldRequireEmailVerificationForRole(?string $role): bool
    {
        return !in_array($role, ['admin', 'superadmin'], true);
    }

    /**
     * @return array<string, null>
     */
    protected function clearEmailHealthAttributes(): array
    {
        return [
            'email_status' => null,
            'verification_sent_at' => null,
            'email_verified_at' => null,
            'email_last_delivery_attempt_at' => null,
            'email_last_bounced_at' => null,
            'email_bounce_reason' => null,
            'email_warning_code' => null,
            'email_warning_message' => null,
            'email_suggested_correction' => null,
        ];
    }

    protected function recordUserActivity(
        User $user,
        string $eventType,
        string $title,
        ?string $description = null,
        array $metadata = []
    ): void {
        try {
            UserActivityLog::record($user, $eventType, $title, $description, null, $metadata);
        } catch (\Throwable $exception) {
            Log::warning('Unable to persist authentication activity.', [
                'user_id' => $user->getKey(),
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
