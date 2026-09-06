<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\ScheduledVoiceCall;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootEditorDownloadService;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class StoredDeliveryErrorSecurityTest extends TestCase
{
    private const CANARY = 'SQLSTATE synthetic-secret-canary Authorization: Bearer fixture /private/fixture.env';

    public function test_scheduled_call_json_redacts_historical_error_without_mutating_model(): void
    {
        $call = new ScheduledVoiceCall();
        $call->setRawAttributes([
            'id' => 912001, 'status' => 'failed', 'attempts' => 2, 'max_attempts' => 5,
            'summary' => 'Call the client about the appointment.', 'last_error' => self::CANARY,
            'metadata' => json_encode(['fixture' => 'preserved']),
        ], true);
        $json = response()->json(new LengthAwarePaginator([$call], 1, 25))->getData(true);
        $this->assertStringNotContainsString(self::CANARY, json_encode($json));
        $this->assertSame('The scheduled call could not be completed. Please retry or contact support.', $json['data'][0]['last_error']);
        $this->assertSame('failed', $json['data'][0]['status']);
        $this->assertSame(2, $json['data'][0]['attempts']);
        $this->assertSame('Call the client about the appointment.', $json['data'][0]['summary']);
        $this->assertSame(['fixture' => 'preserved'], $json['data'][0]['metadata']);
        $this->assertSame(self::CANARY, $call->getRawOriginal('last_error'));
        $call->status = 'scheduled';
        $this->assertSame(['status' => 'scheduled'], $call->getDirty());
    }

    public function test_message_json_redacts_both_legacy_error_locations_without_corrupting_resend_state(): void
    {
        $message = new Message();
        $message->setRawAttributes([
            'id' => 912002, 'status' => 'FAILED', 'subject' => 'Appointment update',
            'body_text' => 'Please confirm your appointment.', 'error_message' => self::CANARY,
            'metadata' => json_encode(['fixture' => 'preserved', 'delivery' => [
                'provider' => 'CAKEMAIL', 'status' => 'FAILED', 'error' => self::CANARY,
            ]]),
        ], true);
        $json = response()->json($message)->getData(true);
        $this->assertStringNotContainsString(self::CANARY, json_encode($json));
        $this->assertSame('The message could not be delivered. Please retry or contact support.', $json['error_message']);
        $this->assertSame($json['error_message'], $json['metadata']['delivery']['error']);
        $this->assertSame('CAKEMAIL', $json['metadata']['delivery']['provider']);
        $this->assertSame('preserved', $json['metadata']['fixture']);
        $this->assertSame('Appointment update', $json['subject']);
        $this->assertSame('Please confirm your appointment.', $json['body_text']);
        $this->assertSame(self::CANARY, $message->error_message);
        $this->assertSame(self::CANARY, $message->metadata['delivery']['error']);
        $message->status = 'SCHEDULED';
        $this->assertSame(['status' => 'SCHEDULED'], $message->getDirty());
    }

    public function test_only_exact_reviewed_sms_guidance_survives_and_empty_errors_stay_empty(): void
    {
        $guidance = 'SMS could not be sent: the Telnyx sending number is not verified.';
        $message = new Message(['error_message' => $guidance, 'metadata' => ['delivery' => ['error' => $guidance]]]);
        $this->assertSame($guidance, $message->toArray()['error_message']);
        $this->assertSame($guidance, $message->toArray()['metadata']['delivery']['error']);
        $message->error_message = $guidance.' '.self::CANARY;
        $this->assertStringNotContainsString(self::CANARY, json_encode($message));
        foreach ([null, ''] as $empty) {
            $message->error_message = $empty;
            $message->metadata = ['delivery' => ['error' => $empty]];
            $call = new ScheduledVoiceCall(['last_error' => $empty]);
            $this->assertNull($message->toArray()['error_message']);
            $this->assertNull($message->toArray()['metadata']['delivery']['error']);
            $this->assertNull($call->toArray()['last_error']);
        }
    }

    public function test_editor_zip_catch_returns_safe_json_instead_of_storage_or_provider_diagnostics(): void
    {
        $file = Mockery::mock(ShootFile::class)->makePartial();
        $file->shouldReceive('isRequiredForEditing')->once()->andReturnTrue();
        $file->shouldReceive('isBlockedFromDelivery')->once()->andReturnFalse();
        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('where')->once()->with('workflow_stage', ShootFile::STAGE_TODO)->andReturnSelf();
        $relation->shouldReceive('get')->once()->andReturn(new Collection([$file]));
        $shoot = Mockery::mock(Shoot::class)->makePartial();
        $shoot->id = 912003;
        $shoot->shouldReceive('files')->once()->andReturn($relation);
        $user = new User(['name' => 'Synthetic Admin', 'role' => 'admin']);
        $user->id = 912004;
        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->once()->andReturnFalse();
        $activity = Mockery::mock(ShootActivityLogger::class);
        $activity->shouldReceive('log')->once();
        $authorization = Mockery::mock(ShootAuthorizationSupport::class);
        $authorization->shouldReceive('hasRole')->once()->with($user, ['editor'])->andReturnFalse();
        $share = Mockery::mock(ShootShareLinkService::class);
        $share->shouldReceive('generateFilesZipWithDropboxFallback')->once()->andThrow(new \RuntimeException(self::CANARY));
        $service = new ShootEditorDownloadService($dropbox, $activity, $authorization, $share, Mockery::mock(ShootEditingAssignmentService::class));

        $response = $service->downloadRaw(Request::create('/api/shoots/912003/editor-download/raw'), $shoot, $user);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['error' => 'The ZIP download could not be prepared. Please try again.'], $response->getData(true));
        $this->assertStringNotContainsString(self::CANARY, $response->getContent());
    }
}
