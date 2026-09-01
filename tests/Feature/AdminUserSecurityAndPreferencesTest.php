<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminUserSecurityAndPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_detail_returns_persisted_activity_and_last_login(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $profileActivity = UserActivityLog::record(
            $target,
            'profile_updated',
            'Notification preferences updated',
            'Email notifications were enabled.',
            $target,
            ['changed_fields' => ['metadata']],
        );
        $loginActivity = UserActivityLog::record(
            $target,
            'login',
            'User logged in',
            'Signed in with password and two-factor authentication.',
        );

        $response = $this->withToken($admin->createToken('admin-profile')->plainTextToken)
            ->getJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('user.id', $target->id)
            ->assertJsonPath('user.lastLogin', $loginActivity->fresh()->occurred_at->toIso8601String())
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'createdAt',
                    'updatedAt',
                    'lastLogin',
                    'activityLog',
                ],
            ]);

        $persistedEntry = collect($response->json('user.activityLog'))
            ->firstWhere('id', 'audit-'.$profileActivity->id);

        $this->assertNotNull($persistedEntry);
        $this->assertSame('profile_updated', $persistedEntry['type']);
        $this->assertSame('Notification preferences updated', $persistedEntry['title']);
        $this->assertSame('Email notifications were enabled.', $persistedEntry['description']);
        $this->assertSame('audit', $persistedEntry['source']);
        $this->assertSame(['metadata'], $persistedEntry['metadata']['changed_fields']);
    }

    public function test_admin_cannot_reset_or_send_a_reset_link_to_a_superadmin(): void
    {
        $admin = User::factory()->admin()->create();
        $superadmin = User::factory()->superAdmin()->create([
            'password' => Hash::make('Original123!'),
        ]);
        $superadminToken = $superadmin->createToken('protected-session');
        $originalPasswordHash = $superadmin->password;

        $this->mock(MailService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendPasswordResetEmail');
        });

        $this->withToken($admin->createToken('admin-security')->plainTextToken)
            ->patchJson("/api/admin/users/{$superadmin->id}/password", [
                'password' => 'Replacement456!',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only a superadmin can reset a superadmin password.');

        $this->postJson("/api/admin/users/{$superadmin->id}/send-reset-link")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only a superadmin can send a reset link to a superadmin.');

        $this->assertSame($originalPasswordHash, $superadmin->fresh()->password);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $superadminToken->accessToken->id,
            'tokenable_id' => $superadmin->id,
        ]);
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', $superadmin->email)->exists());
    }

    public function test_admin_password_reset_revokes_every_target_api_token(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create([
            'password' => Hash::make('Original123!'),
        ]);
        $firstTargetToken = $target->createToken('browser')->accessToken;
        $secondTargetToken = $target->createToken('phone')->accessToken;
        DB::table('password_reset_tokens')->insert([
            'email' => $target->email,
            'token' => Hash::make('stale-reset-token'),
            'created_at' => now(),
        ]);

        $this->mock(AutomationService::class, function (MockInterface $mock) use ($target): void {
            $mock->shouldReceive('handleEvent')
                ->once()
                ->with('PASSWORD_RESET', Mockery::on(
                    fn (array $context): bool => ($context['account_id'] ?? null) === $target->id,
                ))
                ->andReturn([]);
        });

        $this->withToken($admin->createToken('admin-security')->plainTextToken)
            ->patchJson("/api/admin/users/{$target->id}/password", [
                'password' => 'Replacement456!',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.')
            ->assertJsonPath('user_id', $target->id);

        $target->refresh();

        $this->assertTrue(Hash::check('Replacement456!', $target->password));
        $this->assertNotNull($target->password_changed_at);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstTargetToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondTargetToken->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $target->id,
            'actor_user_id' => $admin->id,
            'event_type' => 'password_reset',
        ]);
    }

    public function test_admin_password_reset_rolls_back_when_target_tokens_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create([
            'password' => Hash::make('Original123!'),
        ]);
        $target->createToken('browser');
        $target->createToken('phone');
        $adminToken = $admin->createToken('admin-security')->plainTextToken;

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_admin_reset_token_delete
            BEFORE DELETE ON personal_access_tokens
            BEGIN
                SELECT RAISE(ABORT, 'forced token deletion failure');
            END
        SQL);

        try {
            $this->withToken($adminToken)
                ->patchJson("/api/admin/users/{$target->id}/password", [
                    'password' => 'Replacement456!',
                ])
                ->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_admin_reset_token_delete');
        }

        $target->refresh();
        $this->assertTrue(Hash::check('Original123!', $target->password));
        $this->assertFalse(Hash::check('Replacement456!', $target->password));
        $this->assertNull($target->password_changed_at);
        $this->assertSame(2, $target->tokens()->count());
        $this->assertDatabaseMissing('user_activity_logs', [
            'user_id' => $target->id,
            'event_type' => 'password_reset',
        ]);
    }

    public function test_admin_password_reset_rolls_back_when_reset_link_deletion_fails(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create([
            'email' => 'admin-reset-link-atomicity@example.com',
            'password' => Hash::make('Original123!'),
        ]);
        $target->createToken('browser');
        $target->createToken('phone');
        $adminToken = $admin->createToken('admin-security')->plainTextToken;
        DB::table('password_reset_tokens')->insert([
            'email' => $target->email,
            'token' => Hash::make('stale-reset-token'),
            'created_at' => now(),
        ]);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_admin_reset_link_delete
            BEFORE DELETE ON password_reset_tokens
            BEGIN
                SELECT RAISE(ABORT, 'forced reset link deletion failure');
            END
        SQL);

        try {
            $this->withToken($adminToken)
                ->patchJson("/api/admin/users/{$target->id}/password", [
                    'password' => 'Replacement456!',
                ])
                ->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_admin_reset_link_delete');
        }

        $target->refresh();
        $this->assertTrue(Hash::check('Original123!', $target->password));
        $this->assertFalse(Hash::check('Replacement456!', $target->password));
        $this->assertNull($target->password_changed_at);
        $this->assertSame(2, $target->tokens()->count());
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseMissing('user_activity_logs', [
            'user_id' => $target->id,
            'event_type' => 'password_reset',
        ]);
    }

    public function test_admin_notification_email_preference_update_preserves_other_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create([
            'metadata' => [
                'tax_document_path' => 'tax-documents/existing-w9.pdf',
                'accountRepId' => '42',
                'preferences' => [
                    'notificationEmail' => false,
                    'notificationSMS' => true,
                    'portfolioWebsite' => 'https://portfolio.example.com',
                ],
            ],
        ]);

        $this->withToken($admin->createToken('admin-profile')->plainTextToken)
            ->putJson("/api/admin/users/{$target->id}", [
                'preferences' => [
                    'notificationEmail' => true,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User updated successfully.');

        $metadata = $target->fresh()->metadata;

        $this->assertSame('tax-documents/existing-w9.pdf', $metadata['tax_document_path']);
        $this->assertSame('42', $metadata['accountRepId']);
        $this->assertTrue($metadata['preferences']['notificationEmail']);
        $this->assertTrue($metadata['preferences']['notificationSMS']);
        $this->assertSame(
            'https://portfolio.example.com',
            $metadata['preferences']['portfolioWebsite'],
        );
    }

    public function test_admin_can_update_a_photographer_preference_and_specialties_together(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'metadata' => [
                'specialties' => ['category:old'],
                'insuranceNumber' => 'POLICY-123',
                'preferences' => [
                    'notificationEmail' => false,
                    'weeklyInvoice' => true,
                ],
            ],
        ]);

        $this->withToken($admin->createToken('admin-profile')->plainTextToken)
            ->putJson("/api/admin/users/{$photographer->id}", [
                'specialties' => json_encode(['category:new'], JSON_THROW_ON_ERROR),
                'preferences' => [
                    'notificationEmail' => true,
                ],
            ])
            ->assertOk();

        $metadata = $photographer->fresh()->metadata;

        $this->assertSame(['category:new'], $metadata['specialties']);
        $this->assertSame('POLICY-123', $metadata['insuranceNumber']);
        $this->assertTrue($metadata['preferences']['notificationEmail']);
        $this->assertTrue($metadata['preferences']['weeklyInvoice']);
    }
}
