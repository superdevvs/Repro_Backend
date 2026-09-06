<?php

namespace Tests\Feature;

use App\Exceptions\FalTerminalException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessStudioWorkspaceFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_validation_fails_immediately_and_does_not_run_three_paid_attempts(): void
    {
        $workspace = $this->workspace();
        $exception = new FalTerminalException(422);
        $processor = $this->mock(WorkspaceProcessor::class);
        $processor->shouldReceive('process')->once()->andThrow($exception);
        $job = (new ProcessStudioWorkspace($workspace->id, 'operation-one'))->withFakeQueueInteractions();
        $job->handle($processor);
        $job->assertFailedWith($exception);
        $workspace->refresh();
        $this->assertSame('failed', $workspace->status);
        $this->assertSame($exception->getMessage(), $workspace->error);
        $this->assertSame(['kept-result'], $workspace->outputs);
        $job->handle($processor);
        $this->assertSame(2, $workspace->fresh()->version);
    }

    public function test_account_rejection_fails_once_but_preserves_the_existing_provider_id(): void
    {
        $workspace = $this->workspace();
        $exception = new FalTerminalException(402);
        $processor = $this->mock(WorkspaceProcessor::class);
        $processor->shouldReceive('process')->once()->andThrow($exception);
        $job = (new ProcessStudioWorkspace($workspace->id, 'operation-one'))->withFakeQueueInteractions();
        $job->handle($processor);
        $job->assertFailedWith($exception);
        $workspace->refresh();
        $this->assertSame('failed', $workspace->status);
        $this->assertSame('paid-request-one', $workspace->operation['requests']['media-one']);
        $this->assertStringContainsString('account needs attention', $workspace->error);
    }

    public function test_network_failure_is_left_retryable_without_discarding_saved_progress(): void
    {
        $workspace = $this->workspace();
        $exception = new RuntimeException('Temporary network failure');
        $processor = $this->mock(WorkspaceProcessor::class);
        $processor->shouldReceive('process')->once()->andThrow($exception);
        $job = (new ProcessStudioWorkspace($workspace->id, 'operation-one'))->withFakeQueueInteractions();
        try {
            $job->handle($processor);
            $this->fail('The queue must receive the retryable exception.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        $job->assertNotFailed();
        $workspace->refresh();
        $this->assertSame('preparing', $workspace->status);
        $this->assertSame('paid-request-one', $workspace->operation['requests']['media-one']);
        $this->assertSame(['kept-result'], $workspace->outputs);
    }

    public function test_an_old_job_cannot_fail_a_new_operation_or_expose_an_untrusted_error(): void
    {
        $workspace = $this->workspace();
        $job = new ProcessStudioWorkspace($workspace->id, 'old-operation');
        $job->failed(new FalTerminalException(422));
        $this->assertSame('preparing', $workspace->fresh()->status);
        (new ProcessStudioWorkspace($workspace->id, 'operation-one'))->failed(new RuntimeException('https://private.test/image data:image/jpeg;base64,PRIVATE_PIXELS'));
        $error = $workspace->fresh()->error;
        $this->assertStringNotContainsString('https://', $error);
        $this->assertStringNotContainsString('PRIVATE_PIXELS', $error);
    }

    private function workspace(): StudioWorkspace
    {
        $user = User::factory()->create(['role' => 'admin']);

        return StudioWorkspace::create([
            'team_id' => $user->id, 'created_by' => $user->id, 'name' => 'Provider regression fixture', 'preset_id' => 'walkthrough',
            'media' => [], 'config' => [], 'outputs' => ['kept-result'], 'prepared_frames' => [], 'status' => 'preparing',
            'operation' => ['id' => 'operation-one', 'type' => 'prepare', 'requests' => ['media-one' => 'paid-request-one']],
        ]);
    }
}
