<?php

namespace Tests\Feature;

use App\Jobs\{ImportMessageMedia, ScanMessageMedia};
use App\Models\{Contact, Message, MessageMedia, MessageThread, SmsNumber, SupportTicketMessage, User};
use App\Services\Messaging\{MessageMediaService, OutboundDeliveryGuard};
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use App\Services\Scanning\{ClamAvClient, ClamAvScanResult};
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Queue, Storage, URL};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MessageMediaTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('m', 32)), 'clamav.scan_on_upload' => false]);
        Storage::fake('local'); Storage::fake('message_media', config('filesystems.disks.message_media')); Queue::fake(); Http::preventStrayRequests();
        $this->actor = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($this->actor);
    }
    private function start(int $size = 4, string $channel = 'sms', ?string $id = null): string
    {
        $id ??= (string) Str::uuid();
        $this->postJson('/api/messaging/media/uploads', ['id' => $id, 'name' => 'photo.jpg', 'size' => $size, 'channel' => $channel])->assertCreated();
        return $id;
    }
    private function chunk(string $id, int $index, string $bytes)
    {
        return $this->call('PUT', '/api/messaging/media/uploads/'.$id.'/chunks/'.$index, [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => strlen($bytes), 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }
    private function ready(string $channel = 'sms'): MessageMedia
    {
        $id = $this->start(4, $channel);
        $this->chunk($id, 0, 'test')->assertOk();
        $this->postJson('/api/messaging/media/uploads/'.$id.'/complete')->assertStatus(202)->assertJsonPath('status', 'scanning');
        (new ScanMessageMedia($id))->handle();
        return MessageMedia::findOrFail($id);
    }
    private function smsProvider(int $times = 1): void
    {
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550100', 'label' => 'Office', 'is_default' => true]);
        $mock = Mockery::mock(TelnyxSmsProvider::class);
        $mock->shouldReceive('send')->times($times)->andReturn('fake-media-send');
        $this->app->instance(TelnyxSmsProvider::class, $mock);
    }
    public function test_exact_one_gb_is_accepted_and_larger_files_or_unsafe_names_are_rejected(): void
    {
        $id = $this->start(MessageMediaService::MAX_BYTES);
        $this->getJson('/api/messaging/media/uploads/'.$id)->assertOk()->assertJsonPath('size', 1073741824);
        foreach ([['size' => 1073741825], ['size' => 0], ['name' => '../secret'], ['name' => "bad\nname"]] as $change) {
            $this->postJson('/api/messaging/media/uploads', array_merge(['id' => (string) Str::uuid(), 'name' => 'file.bin', 'size' => 1, 'channel' => 'sms'], $change))->assertUnprocessable();
        }
    }
    public function test_chunks_resume_idempotently_and_reject_changed_or_out_of_order_bytes(): void
    {
        $id = $this->start(MessageMediaService::CHUNK_BYTES + 3);
        $this->chunk($id, 1, 'end')->assertConflict();
        $first = str_repeat('a', MessageMediaService::CHUNK_BYTES);
        $this->chunk($id, 0, $first)->assertOk()->assertJsonPath('received_bytes', MessageMediaService::CHUNK_BYTES);
        $this->chunk($id, 0, $first)->assertOk();
        $this->chunk($id, 0, str_repeat('b', MessageMediaService::CHUNK_BYTES))->assertConflict();
        $this->postJson('/api/messaging/media/uploads/'.$id.'/complete')->assertConflict();
        $this->chunk($id, 1, 'end')->assertOk();
        $this->postJson('/api/messaging/media/uploads/'.$id.'/complete')->assertStatus(202);
        (new ScanMessageMedia($id))->handle();
        $file = MessageMedia::findOrFail($id);
        $this->assertSame(hash('sha256', $first.'end'), $file->sha256);
        $this->assertSame('ready', $file->status);
        $this->assertSame([], glob(dirname($file->path()).'/chunk-*'));
    }
    public function test_cross_user_upload_download_cancel_and_send_are_denied(): void
    {
        $file = $this->ready();
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->getJson('/api/messaging/media/uploads/'.$file->id)->assertNotFound();
        $this->getJson('/api/messaging/media/files/'.$file->id)->assertNotFound();
        $this->deleteJson('/api/messaging/media/uploads/'.$file->id)->assertNotFound();
        $this->postJson('/api/messaging/media/send', ['request_key' => (string) Str::uuid(), 'channel' => 'sms', 'recipient' => '+12025550122', 'attachment_ids' => [$file->id]])->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->postJson('/api/messaging/media/uploads', ['id' => (string) Str::uuid(), 'name' => 'a', 'size' => 1, 'channel' => 'sms'])->assertForbidden();
    }
    public function test_retry_recovers_chunk_written_before_database_acknowledgement(): void
    {
        $id = $this->start();
        $file = MessageMedia::findOrFail($id);
        Storage::disk('message_media')->put($file->directory().'/chunk-0', 'test');
        $this->chunk($id, 0, 'bad!')->assertConflict();
        $this->chunk($id, 0, 'test')->assertOk()->assertJsonPath('received_bytes', 4);
        $this->chunk($id, 0, 'test')->assertOk()->assertJsonPath('received_bytes', 4);
        $this->postJson('/api/messaging/media/uploads/'.$id.'/complete')->assertStatus(202);
        (new ScanMessageMedia($id))->handle();
        $this->assertSame(hash('sha256', 'test'), $file->fresh()->sha256);
    }
    public function test_files_are_quarantined_until_clean_and_infected_files_never_download(): void
    {
        config(['clamav.scan_on_upload' => true]);
        $id = $this->start(); $this->chunk($id, 0, 'test')->assertOk();
        $this->postJson('/api/messaging/media/uploads/'.$id.'/complete')->assertStatus(202);
        $this->getJson('/api/messaging/media/files/'.$id)->assertConflict();
        $scan = Mockery::mock(ClamAvClient::class);
        $scan->shouldReceive('scan')->once()->andReturn(ClamAvScanResult::infected('test'));
        $this->app->instance(ClamAvClient::class, $scan);
        (new ScanMessageMedia($id))->handle();
        $this->assertSame('rejected', MessageMedia::find($id)->status);
        $this->getJson('/api/messaging/media/files/'.$id)->assertConflict();
    }
    public function test_one_gb_file_is_hashed_and_released_without_loading_it_into_memory(): void
    {
        $id = $this->start(MessageMediaService::MAX_BYTES);
        $file = MessageMedia::findOrFail($id);
        $stream = fopen($file->path(), 'wb'); ftruncate($stream, MessageMediaService::MAX_BYTES); fclose($stream);
        $file->update(['received_bytes' => $file->size, 'status' => 'scanning']);
        $before = memory_get_usage(true);
        (new ScanMessageMedia($id))->handle();
        $this->assertSame('ready', $file->fresh()->status);
        $this->assertSame(64, strlen($file->fresh()->sha256));
        $this->assertLessThan(64 * 1024 * 1024, memory_get_usage(true) - $before);
    }
    public function test_attachment_only_sms_has_links_and_retry_cannot_send_twice(): void
    {
        $this->smsProvider(); $file = $this->ready();
        $draft = ['request_key' => (string) Str::uuid(), 'channel' => 'sms', 'recipient' => '+12025550122', 'attachment_ids' => [$file->id]];
        $sent = $this->postJson('/api/messaging/media/send', $draft)->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('/api/messaging/media/shared/', Message::sole()->body_text);
        $this->assertSame((string) Message::sole()->id, $file->fresh()->message_id);
        $this->postJson('/api/messaging/media/uploads', ['id' => $file->id, 'name' => $file->name, 'size' => $file->size, 'channel' => 'sms'])
            ->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('received_bytes', 4);
        $this->postJson('/api/messaging/media/send', $draft)->assertOk()->assertJsonPath('id', $sent->json('id'));
        $this->postJson('/api/messaging/media/send', [...$draft, 'body' => 'different'])->assertConflict();
        $this->assertDatabaseCount('messages', 1);
        $this->deleteJson('/api/messaging/media/uploads/'.$file->id)->assertConflict();
        $this->get(app(MessageMediaService::class)->shareURL($file->fresh()))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/messaging/media/shared/'.$file->id)->assertNotFound();
        Message::sole()->delete();
        $this->get(app(MessageMediaService::class)->shareURL($file->fresh()))->assertNotFound();
    }
    public function test_support_attachment_and_reactions_are_visible_only_to_ticket_participants(): void
    {
        $client = User::factory()->create(['role' => 'client']); Sanctum::actingAs($client);
        $file = $this->ready('support');
        $this->postJson('/api/messaging/media/send', ['request_key' => (string) Str::uuid(), 'channel' => 'support', 'subject' => 'Help with files', 'attachment_ids' => [$file->id]])->assertStatus(201)->assertJsonPath('success', true);
        $message = SupportTicketMessage::sole();
        $route = '/api/messaging/media/reactions/support/'.$message->id;
        $this->putJson($route, ['request_key' => (string) Str::uuid(), 'emoji' => '❤️'])->assertOk();
        $this->postJson('/api/messaging/media/content', ['kind' => 'support', 'ids' => [(string) $message->id]])->assertOk()
            ->assertJsonPath('data.'.$message->id.'.reactions.0.emoji', '❤️')->assertJsonPath('data.'.$message->id.'.reactions.0.mine', true)
            ->assertJsonPath('data.'.$message->id.'.attachments.0.id', $file->id);
        $webDownload = '/api/support/tickets/'.$message->support_ticket_id.'/messages/'.$message->id.'/attachments/0';
        $this->getJson('/api/support/tickets/'.$message->support_ticket_id)->assertOk()
            ->assertJsonPath('messages.0.attachments.0.id', $file->id)
            ->assertJsonPath('messages.0.attachments.0.index', 0)
            ->assertJsonPath('messages.0.attachments.0.download_url', $webDownload);
        $this->getJson($webDownload)->assertOk()->assertDownload('photo.jpg');
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson($webDownload)->assertNotFound();
        $this->getJson('/api/messaging/media/files/'.$file->id)->assertNotFound();
        $this->putJson($route, ['request_key' => (string) Str::uuid(), 'emoji' => '👍'])->assertNotFound();
        $this->postJson('/api/messaging/media/content', ['kind' => 'support', 'ids' => [(string) $message->id]])->assertNotFound();
    }
    public function test_reaction_updates_remove_without_duplicates_and_reject_non_emoji(): void
    {
        $ticket = app(SupportTicketService::class)->create($this->actor, ['request_key' => (string) Str::uuid(), 'subject' => 'Test case', 'body' => 'Message to react to', 'category' => 'other']);
        $id = $ticket->messages()->value('id'); $route = '/api/messaging/media/reactions/support/'.$id;
        $this->putJson($route, ['request_key' => (string) Str::uuid(), 'emoji' => '👍'])->assertOk();
        $this->putJson($route, ['request_key' => (string) Str::uuid(), 'emoji' => '🎉'])->assertOk();
        $this->assertDatabaseCount('message_reactions', 1);
        $this->putJson($route, ['request_key' => (string) Str::uuid(), 'emoji' => '<script>'])->assertUnprocessable();
        $this->putJson($route, ['request_key' => (string) Str::uuid(), 'emoji' => null])->assertOk();
        $this->assertDatabaseCount('message_reactions', 0);
    }
    public function test_inbound_media_are_recorded_once_and_private_urls_are_not_exposed(): void
    {
        $service = app(MessageMediaService::class);
        $media = [['url' => 'https://media.example.test/image.jpg?secret=private', 'content_type' => 'image/jpeg'], ['url' => 'http://localhost/private']];
        $service->incoming('sms', '123', $media); $service->incoming('sms', '123', $media);
        $this->assertDatabaseCount('message_media', 1);
        Queue::assertPushed(ImportMessageMedia::class, 1);
        $this->assertStringNotContainsString('secret', json_encode($service->payload(MessageMedia::sole())));
        $this->assertStringNotContainsString('https://', DB::table('message_media')->value('source_url'));
    }
    public function test_expired_and_cancelled_uploads_cannot_be_completed_or_sent(): void
    {
        $id = $this->start();
        $this->deleteJson('/api/messaging/media/uploads/'.$id)->assertNoContent();
        $this->chunk($id, 0, 'test')->assertStatus(410);
        MessageMedia::whereKey($id)->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/messaging/media/uploads/'.$id.'/complete')->assertStatus(410);
        $this->artisan('messaging:prune-uploads')->assertSuccessful();
        $this->assertDatabaseCount('message_media', 0);
    }

    public function test_sms_reactions_send_one_fallback_and_preserve_target_metadata(): void
    {
        $this->smsProvider(1);
        $contact = Contact::create(['name' => 'Fixture', 'phone' => '+12025550122']);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => 'SMS']);
        $target = Message::create(['channel' => 'SMS', 'thread_id' => $thread->id, 'direction' => 'INBOUND', 'from_address' => $contact->phone,
            'to_address' => '+12025550100', 'body_text' => 'A real test message', 'status' => 'DELIVERED']);
        $draft = ['request_key' => (string) Str::uuid(), 'emoji' => '👍'];
        $route = '/api/messaging/media/reactions/sms/'.$target->id;
        $this->putJson($route, $draft)->assertOk()->assertJsonPath('saved', true);
        $this->putJson($route, $draft)->assertOk()->assertJsonPath('saved', true);
        $event = Message::where('direction', 'OUTBOUND')->sole();
        $this->assertSame('Reacted 👍 to “A real test message”', $event->body_text);
        $this->assertSame((string) $target->id, $event->metadata['app_reaction']['targetMessageId']);
        $this->assertDatabaseCount('message_reactions', 1);
        $this->getJson('/api/messaging/sms/threads/'.$thread->id)->assertOk()->assertJsonPath('messages.1.reaction.emoji', '👍');
    }

    public function test_email_sends_a_file_link_and_reaction_does_not_send_another_email(): void
    {
        config(['messaging.email_primary' => 'RESEND', 'services.resend.key' => 'test-only', 'services.resend.domain_verified' => true]);
        $this->app->instance(\App\Services\Messaging\Providers\ResendProvider::class, new \App\Services\Messaging\Providers\ResendProvider);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'media-email-1'])]);
        $file = $this->ready('email');
        $draft = ['request_key' => (string) Str::uuid(), 'channel' => 'email', 'recipient' => 'fixture@example.com', 'subject' => 'Your files', 'attachment_ids' => [$file->id]];
        $this->postJson('/api/messaging/media/send', $draft)->assertOk()->assertJsonPath('success', true);
        $this->postJson('/api/messaging/media/send', $draft)->assertOk();
        $id = Message::sole()->id;
        Http::assertSent(fn ($r) => str_contains($r['text'], '/api/messaging/media/shared/'));
        $this->putJson('/api/messaging/media/reactions/email/'.$id, ['request_key' => (string) Str::uuid(), 'emoji' => '🎉'])->assertOk();
        Http::assertSentCount(1);
        $this->assertSame((string) $id, $file->fresh()->message_id);
    }

    public function test_unconfirmed_send_is_never_reissued_and_attachments_are_reserved(): void
    {
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550100', 'label' => 'Office', 'is_default' => true]);
        $mock = Mockery::mock(TelnyxSmsProvider::class);
        $mock->shouldReceive('send')->once()->with(Mockery::any(), Mockery::on(fn ($payload) => $payload['single_attempt'] === true))->andThrow(new \RuntimeException('Fixture response lost'));
        $this->app->instance(TelnyxSmsProvider::class, $mock);
        $file = $this->ready();
        $draft = ['request_key' => (string) Str::uuid(), 'channel' => 'sms', 'recipient' => '+12025550122', 'attachment_ids' => [$file->id]];
        $this->postJson('/api/messaging/media/send', $draft)->assertUnprocessable()->assertJsonPath('success', false);
        $this->postJson('/api/messaging/media/send', $draft)->assertUnprocessable();
        $this->postJson('/api/messaging/media/send', [...$draft, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_inbound_fetch_cannot_resolve_a_private_ip(): void
    {
        $this->app->instance(\App\Services\Studio\Providers\FotelloTransferUrl::class, new class extends \App\Services\Studio\Providers\FotelloTransferUrl {
            protected function resolve(string $host): array { return ['127.0.0.1']; }
        });
        app(MessageMediaService::class)->incoming('sms', '123', [['url' => 'https://media.example.test/a.jpg', 'content_type' => 'image/jpeg']]);
        try { (new ImportMessageMedia(MessageMedia::sole()->id))->handle(); $this->fail('Private address must fail.'); }
        catch (\App\Services\Studio\Providers\FotelloException) { Http::assertNothingSent(); }
        $this->assertSame('importing', MessageMedia::sole()->status);
    }
    public function test_inbound_redirect_is_not_downloaded_and_only_clean_final_bytes_are_imported(): void
    {
        $this->app->instance(\App\Services\Studio\Providers\FotelloTransferUrl::class, new class extends \App\Services\Studio\Providers\FotelloTransferUrl {
            protected function resolve(string $host): array { return ['8.8.8.8']; }
        });
        Http::fake(['media.example.test/*' => Http::sequence()->push('', 302, ['Location' => 'https://internal.example.test/secret'])->push('photo', 200)]);
        app(MessageMediaService::class)->incoming('sms', '123', [['url' => 'https://media.example.test/a.jpg']]);
        $file = MessageMedia::sole();
        try { (new ImportMessageMedia($file->id))->handle(); $this->fail('Redirect must not import.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(502, $error->getStatusCode()); }
        Http::assertSentCount(1);
        $this->assertSame('importing', $file->fresh()->status);
        (new ImportMessageMedia($file->id))->handle();
        $this->assertSame('ready', $file->fresh()->status);
        $this->assertNull($file->fresh()->source_url);
        $this->assertSame('photo', file_get_contents($file->path()));
    }
    public function test_existing_email_attachment_is_authenticated_without_exposing_storage_paths(): void
    {
        Storage::disk('local')->put('messaging-attachments/legacy.pdf', 'original-file');
        $message = Message::create(['channel' => 'EMAIL', 'direction' => 'OUTBOUND', 'user_id' => $this->actor->id,
            'to_address' => 'fixture@example.com', 'body_text' => 'Original email', 'status' => 'SENT', 'attachments_json' => [
                ['name' => 'Original.pdf', 'size' => 13, 'type' => 'application/pdf', 'disk' => 'local', 'storage_path' => 'messaging-attachments/legacy.pdf'],
                ['name' => 'Unsafe', 'storage_path' => 'messaging-attachments/../../private'],
            ]]);
        $response = $this->postJson('/api/messaging/media/content', ['kind' => 'email', 'ids' => [(string) $message->id]])->assertOk()->assertJsonCount(1, 'data.'.$message->id.'.attachments');
        $path = $response->json('data.'.$message->id.'.attachments.0.download_url');
        $this->assertStringNotContainsString('storage_path', $response->getContent());
        $this->get($path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/messaging/media/legacy/email/'.$message->id.'/1')->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson($path)->assertForbidden();
    }
    public function test_validation_after_message_creation_cannot_release_a_reserved_send(): void
    {
        $file = $this->ready();
        $controller = Mockery::mock(\App\Http\Controllers\API\Messaging\SmsMessagingController::class);
        $controller->shouldReceive('send')->once()->andReturnUsing(function () {
            $message = Message::create(['channel' => 'SMS', 'direction' => 'OUTBOUND', 'created_by' => $this->actor->id,
                'to_address' => '+12025550122', 'body_text' => 'A message was recorded', 'status' => 'FAILED']);
            MessageMediaService::bindPending('sms', (string) $message->id, $this->actor->id);
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'Fixture downstream validation']);
        });
        $this->app->instance(\App\Http\Controllers\API\Messaging\SmsMessagingController::class, $controller);
        $draft = ['request_key' => (string) Str::uuid(), 'channel' => 'sms', 'recipient' => '+12025550122', 'attachment_ids' => [$file->id]];
        $this->postJson('/api/messaging/media/send', $draft)->assertUnprocessable();
        $this->postJson('/api/messaging/media/send', $draft)->assertConflict()->assertJsonPath('status', 'uncertain');
        $this->assertDatabaseCount('messages', 1);
        $this->assertNotNull($file->fresh()->send_request_key);
    }
    public function test_private_file_permissions_allow_scanning_worker_group_without_world_access(): void
    {
        $file = $this->ready();
        clearstatcache();
        $this->assertSame(0660, fileperms($file->path()) & 0777);
        $this->assertSame(02770, fileperms(dirname($file->path())) & 07777);
        $this->assertSame(02770, fileperms(Storage::disk('message_media')->path('')) & 07777);
        $this->assertSame(posix_getgrnam('www-data')['gid'], filegroup($file->path()));
    }
}
