<?php

namespace Tests\Feature;

use App\Models\ListingStudioTrialClaim;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingStudioTrialTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/listing-studio/free-edits';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['studio.client_access_enabled' => false]);
    }

    private function client(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'client', 'email_verified_at' => now(), ...$attributes]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'request_key' => (string) Str::uuid(),
            'edit_types' => ['virtual_staging', 'decluttering', 'twilight'],
            'notes' => 'Keep the architectural details intact.',
            'attachments' => [UploadedFile::fake()->image('living-room.jpg', 80, 60),
                UploadedFile::fake()->image('kitchen.png', 90, 60), UploadedFile::fake()->image('exterior.jpg', 100, 60)],
            ...$overrides,
        ];
    }

    public function test_status_and_submission_require_an_active_client_with_support_access(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
        foreach (['admin', 'superadmin', 'salesRep', 'editor', 'photographer'] as $role) {
            $this->actingAs($this->client(['role' => $role]), 'sanctum')->getJson(self::ENDPOINT)->assertForbidden();
            $this->postJson(self::ENDPOINT, $this->payload())->assertForbidden();
        }
        foreach ([['locked_at' => now()], ['account_status' => 'inactive'], ['deleted_at' => now()]] as $attributes) {
            $this->actingAs($this->client($attributes), 'sanctum')->getJson(self::ENDPOINT)->assertUnauthorized();
            $this->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
        }
        $denied = $this->client(['permission_overrides' => ['deny' => ['support-view'], 'allow' => []]]);
        $this->actingAs($denied, 'sanctum')->getJson(self::ENDPOINT)->assertForbidden();
        $this->postJson(self::ENDPOINT, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('listing_studio_trial_claims', 0);
    }

    public function test_offer_is_available_to_existing_verified_clients_without_subscription_or_studio_access(): void
    {
        $client = $this->client(['created_at' => now()->subYears(2)]);
        $this->actingAs($client, 'sanctum')->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.eligible', true)->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.offer.photo_count', 3)->assertJsonCount(4, 'data.offer.edit_types');
        $data = $this->payload();
        $response = $this->postJson(self::ENDPOINT, $data)->assertCreated()->assertJsonPath('data.status', 'open');
        $ticket = SupportTicket::findOrFail($response->json('data.ticket_id'));
        $this->assertSame('Listing Studio — 3 free photo edits', $ticket->subject);
        $this->assertSame($client->id, $ticket->requester_id);
        $message = $ticket->messages()->sole();
        $this->assertCount(3, $message->attachments_json);
        $this->assertStringContainsString('1. living-room.jpg — Virtual staging', $message->body);
        $this->assertStringContainsString('2. kitchen.png — Digital decluttering', $message->body);
        $this->assertStringContainsString('3. exterior.jpg — Digital dusk / twilight', $message->body);
        $this->assertStringContainsString($data['notes'], $message->body);
        $this->assertCount(3, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('listing_studio_subscriptions', 0);
        $this->assertDatabaseCount('listing_studio_credit_entries', 0);
        $this->assertDatabaseCount('studio_workspaces', 0);
        $this->assertFalse(config('studio.client_access_enabled'));
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $admin = $this->client(['role' => 'admin']);
        $this->assertCount(1, app(SupportTicketService::class)->notifications($admin));
    }

    public function test_impersonated_clients_cannot_consume_the_offer(): void
    {
        $client = $this->client();
        $admin = $this->client(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->postJson(self::ENDPOINT, $this->payload())->assertForbidden()->assertJsonPath('code', 'trial_client_required');
        $this->assertDatabaseCount('listing_studio_trial_claims', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_current_email_must_be_verified_before_claiming(): void
    {
        foreach ([['email_verified_at' => null], ['email_verified_email' => 'previous@example.test', 'email_verification_required_at' => now()]] as $attributes) {
            $this->actingAs($this->client($attributes), 'sanctum')->getJson(self::ENDPOINT)->assertOk()
                ->assertJsonPath('data.eligible', false)->assertJsonPath('data.reason', 'email_not_verified');
            $this->postJson(self::ENDPOINT, $this->payload())->assertForbidden()->assertJsonPath('code', 'email_not_verified');
        }
        $this->assertDatabaseCount('listing_studio_trial_claims', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_exact_retries_reuse_claim_and_delete_new_upload_copies(): void
    {
        $this->actingAs($this->client(), 'sanctum');
        $data = $this->payload();
        $claim = $this->postJson(self::ENDPOINT, $data)->assertCreated()->json('data');
        $this->postJson(self::ENDPOINT, $data)->assertOk()->assertExactJson(['data' => $claim]);
        $this->assertDatabaseCount('listing_studio_trial_claims', 1);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_ticket_messages', 1);
        $this->assertCount(3, Storage::disk('local')->allFiles());
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.reason', 'already_claimed')
            ->assertJsonPath('data.claim', $claim);
    }

    public function test_new_key_or_changed_payload_cannot_reset_an_existing_claim(): void
    {
        $client = $this->client();
        $this->actingAs($client, 'sanctum');
        $data = $this->payload();
        $claim = $this->postJson(self::ENDPOINT, $data)->assertCreated()->json('data');
        foreach ([['request_key' => (string) Str::uuid()], ['notes' => 'Different notes'],
            ['edit_types' => ['green_grass', 'decluttering', 'twilight']]] as $changes) {
            $this->postJson(self::ENDPOINT, [...$data, ...$changes])->assertConflict()
                ->assertJsonPath('code', 'trial_already_claimed')->assertJsonPath('data', $claim);
        }
        $client->update(['email' => 'changed@example.test']);
        app(\App\Services\Users\EmailHealthService::class)->markVerified($client->fresh());
        $this->actingAs($client->fresh(), 'sanctum')->postJson(self::ENDPOINT, $this->payload())->assertConflict();
        $this->assertDatabaseCount('listing_studio_trial_claims', 1);
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_normalized_email_claim_is_not_reissued_or_exposed_to_another_account(): void
    {
        $first = $this->client(['email' => 'Already@Example.test']);
        $this->actingAs($first, 'sanctum')->postJson(self::ENDPOINT, $this->payload())->assertCreated();
        $first->update(['email' => 'moved@example.test']);
        $second = $this->client(['email' => 'already@example.test']);
        $this->actingAs($second, 'sanctum')->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.reason', 'email_already_claimed')->assertJsonPath('data.claim', null);
        $this->postJson(self::ENDPOINT, $this->payload())->assertConflict()
            ->assertJsonPath('code', 'trial_already_claimed')->assertJsonPath('data', null);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_exactly_three_distinct_real_images_and_matching_supported_edits_are_required(): void
    {
        $this->actingAs($this->client(), 'sanctum');
        $base = $this->payload();
        foreach ([[], array_slice($base['attachments'], 0, 2), [...$base['attachments'], UploadedFile::fake()->image('fourth.jpg', 110, 60)]] as $files) {
            $this->postJson(self::ENDPOINT, [...$base, 'attachments' => $files])->assertUnprocessable();
        }
        foreach ([['virtual_staging'], ['virtual_staging', 'unsupported', 'twilight'], ['0' => 'virtual_staging', '2' => 'decluttering', '3' => 'twilight']] as $types) {
            $this->postJson(self::ENDPOINT, [...$base, 'edit_types' => $types])->assertUnprocessable();
        }
        foreach ([UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "bad";'),
            UploadedFile::fake()->image('too-large.jpg', 110, 60)->size(10241),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>'),
            $base['attachments'][0]] as $invalid) {
            $response = $this->postJson(self::ENDPOINT, [...$base, 'attachments' => [$base['attachments'][0], $base['attachments'][1], $invalid]]);
            $this->assertSame(422, $response->status(), $invalid->getClientOriginalName().': '.$response->getContent());
        }
        $this->assertDatabaseCount('listing_studio_trial_claims', 0);
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_failed_claim_write_rolls_back_ticket_and_cleans_all_private_uploads(): void
    {
        $this->actingAs($this->client(), 'sanctum');
        ListingStudioTrialClaim::creating(fn () => throw new \RuntimeException('Simulated claim storage failure.'));
        try {
            $this->postJson(self::ENDPOINT, $this->payload())->assertServerError();
        } finally {
            ListingStudioTrialClaim::flushEventListeners();
        }
        $this->assertDatabaseCount('listing_studio_trial_claims', 0);
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('support_ticket_messages', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_partial_upload_failure_does_not_leave_files_or_consume_eligibility(): void
    {
        $this->actingAs($this->client(), 'sanctum');
        $disk = Storage::disk('local');
        $failing = \Mockery::mock($disk);
        $writes = 0;
        $failing->shouldReceive('putFileAs')->andReturnUsing(function (...$arguments) use ($disk, &$writes) {
            if (++$writes === 2) {
                throw new \RuntimeException('Simulated private storage failure.');
            }
            return $disk->putFileAs(...$arguments);
        });
        Storage::set('local', $failing);
        $this->postJson(self::ENDPOINT, $this->payload())->assertServerError();
        $this->assertCount(0, $disk->allFiles());
        $this->assertDatabaseCount('listing_studio_trial_claims', 0);
        $this->assertDatabaseCount('support_tickets', 0);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.eligible', true);
    }

    public function test_database_uniqueness_keeps_one_claim_per_client_and_per_email(): void
    {
        $this->actingAs($this->client(), 'sanctum')->postJson(self::ENDPOINT, $this->payload())->assertCreated();
        $claim = ListingStudioTrialClaim::sole();
        $other = $this->client();
        $ticket = app(SupportTicketService::class)->create($other, ['request_key' => (string) Str::uuid(), 'subject' => 'Separate request', 'body' => 'A separate ordinary support request.', 'category' => 'other']);
        foreach ([['client_id' => $claim->client_id, 'email_hash' => str_repeat('a', 64)],
            ['client_id' => $other->id, 'email_hash' => $claim->email_hash]] as $identity) {
            try {
                DB::table('listing_studio_trial_claims')->insert([...$identity, 'support_ticket_id' => $ticket->id,
                    'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'edit_types' => '[]']);
                $this->fail('The database must reject a second claim identity.');
            } catch (QueryException) {
                $this->assertDatabaseCount('listing_studio_trial_claims', 1);
            }
        }
    }

    public function test_private_originals_and_staff_results_follow_existing_support_permissions_and_status(): void
    {
        $owner = $this->client();
        $claim = $this->actingAs($owner, 'sanctum')->postJson(self::ENDPOINT, $this->payload())->assertCreated()->json('data');
        $ticketId = $claim['ticket_id'];
        $detail = $this->getJson('/api/support/tickets/'.$ticketId)->assertOk();
        $download = $detail->json('messages.0.attachments.0.download_url');
        $this->get($download)->assertOk()->assertDownload('living-room.jpg');
        $this->actingAs($this->client(), 'sanctum')->getJson($download)->assertNotFound();
        $admin = $this->client(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->get($download)->assertOk();
        $this->postJson('/api/support/tickets/'.$ticketId.'/replies', [
            'request_key' => (string) Str::uuid(), 'body' => 'Here are your edited photos.',
            'attachments' => [UploadedFile::fake()->image('edited-living-room.jpg', 100, 80)],
        ])->assertOk();
        $this->patchJson('/api/support/tickets/'.$ticketId, ['version' => 2, 'status' => 'resolved'])->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.claim.status', 'resolved');
        $resultDownload = $this->getJson('/api/support/tickets/'.$ticketId)->json('messages.1.attachments.0.download_url');
        $this->get($resultDownload)->assertOk()->assertDownload('edited-living-room.jpg');
        $admin->update(['permission_overrides' => ['deny' => ['support-manage'], 'allow' => []]]);
        $this->actingAs($admin->fresh(), 'sanctum')->getJson($download)->assertNotFound();
    }
}
