<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_self_service_profile_fields_and_metadata_preferences(): void
    {
        $user = User::factory()->photographer()->create([
            'password' => Hash::make('Secret123!'),
            'metadata' => [
                'tax_document_name' => 'existing-w9.pdf',
                'tax_document_path' => 'tax-documents/existing-w9.pdf',
                'preferences' => [
                    'portfolioWebsite' => 'https://old.example.com',
                ],
            ],
        ]);

        $token = $user->createToken('profile-update')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'name' => 'Updated Photographer',
                'phone_number' => '555-123-4567',
                'company_name' => 'Repro Updated',
                'address' => '123 Main St',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'timezone' => 'America/New_York',
                'facebook_url' => 'https://facebook.com/repro',
                'preferences' => [
                    'portfolioWebsite' => 'https://portfolio.example.com',
                    'weeklyInvoice' => true,
                    'notificationEmail' => true,
                ],
                'travel_range' => 45,
                'travel_range_unit' => 'miles',
            ]);

        $response->assertOk()
            ->assertJsonPath('reauth_required', false)
            ->assertJsonPath('user.name', 'Updated Photographer')
            ->assertJsonPath('user.company_name', 'Repro Updated')
            ->assertJsonPath('user.timezone', 'America/New_York');

        $user->refresh();

        $this->assertSame('Updated Photographer', $user->name);
        $this->assertSame('555-123-4567', $user->phonenumber);
        $this->assertSame('Repro Updated', $user->company_name);
        $this->assertNotSame('123 Main St', $user->address);
        $this->assertTrue((bool) $response->json('address_change_pending'));
        $this->assertDatabaseHas('photographer_address_change_requests', [
            'user_id' => $user->id,
            'street_address' => '123 Main St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'status' => 'pending',
        ]);
        $this->assertSame('America/New_York', $user->timezone);
        $this->assertSame('https://facebook.com/repro', $user->facebook_url);
        $this->assertSame('tax-documents/existing-w9.pdf', $user->metadata['tax_document_path']);
        $this->assertSame('https://portfolio.example.com', $user->metadata['preferences']['portfolioWebsite']);
        $this->assertTrue($user->metadata['preferences']['weeklyInvoice']);
        $this->assertTrue($user->metadata['preferences']['notificationEmail']);
        $this->assertSame(45, $user->metadata['travel_range']);
        $this->assertSame('miles', $user->metadata['travel_range_unit']);
    }

    public function test_email_change_requires_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $token = $user->createToken('email-update')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'email' => 'new-email@example.com',
            ]);

        $response->assertStatus(422)
            ->assertSeeText('The current password is incorrect.');
    }

    public function test_duplicate_email_change_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Secret123!'),
        ]);
        $otherUser = User::factory()->create([
            'email' => 'taken@example.com',
        ]);

        $token = $user->createToken('duplicate-email')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'email' => $otherUser->email,
                'current_password' => 'Secret123!',
            ]);

        $response->assertStatus(422)
            ->assertSeeText('The email has already been taken.');
    }

    public function test_password_change_requires_correct_current_password_and_confirmation(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $token = $user->createToken('password-update')->plainTextToken;

        $wrongPasswordResponse = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'current_password' => 'WrongPassword!',
                'new_password' => 'Updated456!',
                'new_password_confirmation' => 'Updated456!',
            ]);

        $wrongPasswordResponse->assertStatus(422)
            ->assertSeeText('The current password is incorrect.');

        $confirmationResponse = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'current_password' => 'Secret123!',
                'new_password' => 'Updated456!',
                'new_password_confirmation' => 'Mismatch789!',
            ]);

        $confirmationResponse->assertStatus(422)
            ->assertSeeText('The new password field confirmation does not match.');
    }

    public function test_email_change_revokes_current_token_and_requires_reauthentication(): void
    {
        $user = User::factory()->create([
            'email' => 'old-email@example.com',
            'password' => Hash::make('Secret123!'),
        ]);

        $token = $user->createToken('reauth-email')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'email' => 'new-email@example.com',
                'current_password' => 'Secret123!',
            ]);

        $response->assertOk()
            ->assertJsonPath('reauth_required', true)
            ->assertJsonPath('user.email', 'new-email@example.com');

        $tokenId = (int) explode('|', $token, 2)[0];
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ]);
    }

    public function test_password_change_revokes_current_token_and_requires_reauthentication(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $token = $user->createToken('reauth-password')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'current_password' => 'Secret123!',
                'new_password' => 'Updated456!',
                'new_password_confirmation' => 'Updated456!',
            ]);

        $response->assertOk()
            ->assertJsonPath('reauth_required', true);

        $this->assertTrue(Hash::check('Updated456!', $user->fresh()->password));

        $tokenId = (int) explode('|', $token, 2)[0];
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ]);
    }

    public function test_admin_only_fields_are_ignored_by_self_service_profile_updates(): void
    {
        $user = User::factory()->create([
            'role' => 'client',
            'account_status' => 'active',
            'client_discount_type' => null,
            'client_discount_value' => null,
        ]);

        $token = $user->createToken('protected-fields')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'name' => 'Safe Update',
                'role' => 'admin',
                'account_status' => 'inactive',
                'client_discount_type' => 'percent',
                'client_discount_value' => 15,
                'service_group_ids' => [1, 2],
            ]);

        $response->assertOk()
            ->assertJsonPath('reauth_required', false);

        $user->refresh();

        $this->assertSame('Safe Update', $user->name);
        $this->assertSame('client', $user->role);
        $this->assertSame('active', $user->account_status);
        $this->assertNull($user->client_discount_type);
        $this->assertNull($user->client_discount_value);
    }

    public function test_profile_update_with_about_field_works_when_users_table_has_no_about_column(): void
    {
        $user = User::factory()->create([
            'role' => 'client',
        ]);

        $token = $user->createToken('about-fallback')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'name' => 'Client With Bio',
                'phonenumber' => '1234567890',
                'about' => 'Client bio stored through compatibility fallback.',
                'preferences' => [
                    'notificationEmail' => true,
                    'notificationSMS' => true,
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Client With Bio')
            ->assertJsonPath('user.about', 'Client bio stored through compatibility fallback.');

        $user->refresh();

        $this->assertSame('Client With Bio', $user->name);
        $this->assertSame('1234567890', $user->phonenumber);
        $this->assertSame('Client bio stored through compatibility fallback.', $user->about);
        $this->assertSame(
            'Client bio stored through compatibility fallback.',
            $user->metadata['about'] ?? null,
        );
    }
}
