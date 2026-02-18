<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\Messaging\AutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinalizeShootJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $backoff = [30, 120];
    public $timeout = 1200;

    public function __construct(
        public int $shootId,
        public int $userId,
        public ?string $finalStatus = null
    ) {
        $this->onQueue('default');
    }

    public function handle(DropboxWorkflowService $dropboxService, AutomationService $automationService): void
    {
        $lock = Cache::lock("shoot:finalize:{$this->shootId}", 300);
        if (!$lock->get()) {
            Log::info('Finalize skipped because another job is already running', [
                'shoot_id' => $this->shootId,
                'user_id' => $this->userId,
            ]);
            return;
        }

        $processedFiles = 0;
        $shoot = null;

        try {
            $shoot = Shoot::with(['files'])->find($this->shootId);
            if (!$shoot) {
                Log::warning('Finalize job aborted: shoot not found', [
                    'shoot_id' => $this->shootId,
                    'user_id' => $this->userId,
                ]);
                return;
            }

            $completedFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_COMPLETED)->get();
            $rawFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->get();

            $hasEditedWithoutRaw = $completedFiles->isNotEmpty() && $rawFiles->isEmpty();
            $isInEditingStatus = $shoot->workflow_status === Shoot::STATUS_EDITING;

            if (!$isInEditingStatus && !$hasEditedWithoutRaw) {
                $shoot->workflowLogs()->create([
                    'user_id' => $this->userId,
                    'action' => 'finalize_failed',
                    'details' => 'Finalize aborted: invalid shoot status for finalization',
                    'metadata' => [
                        'current_status' => $shoot->workflow_status,
                        'final_status' => $this->finalStatus,
                        'failed_at' => now()->toISOString(),
                    ],
                ]);
                return;
            }

            if ($completedFiles->isEmpty()) {
                $shoot->workflowLogs()->create([
                    'user_id' => $this->userId,
                    'action' => 'finalize_failed',
                    'details' => 'Finalize aborted: no edited files found',
                    'metadata' => [
                        'current_status' => $shoot->workflow_status,
                        'final_status' => $this->finalStatus,
                        'failed_at' => now()->toISOString(),
                    ],
                ]);
                return;
            }

            $totalFiles = $completedFiles->count();
            $shoot->workflowLogs()->create([
                'user_id' => $this->userId,
                'action' => 'finalize_started',
                'details' => 'Finalize background processing started',
                'metadata' => [
                    'started_at' => now()->toISOString(),
                    'total_files' => $totalFiles,
                    'final_status' => $this->finalStatus,
                ],
            ]);

            foreach ($completedFiles as $file) {
                $dropboxService->moveToFinal($file, $this->userId);
                $processedFiles++;

                if ($processedFiles % 5 === 0 || $processedFiles === $totalFiles) {
                    $shoot->workflowLogs()->create([
                        'user_id' => $this->userId,
                        'action' => 'finalize_progress',
                        'details' => "Finalize progress: {$processedFiles}/{$totalFiles} files processed",
                        'metadata' => [
                            'processed_files' => $processedFiles,
                            'total_files' => $totalFiles,
                            'updated_at' => now()->toISOString(),
                        ],
                    ]);
                }
            }

            $shoot->updateWorkflowStatus(Shoot::STATUS_DELIVERED, $this->userId);

            $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
            $context = $automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
            $automationService->handleEvent('SHOOT_COMPLETED', $context);

            $shoot->workflowLogs()->create([
                'user_id' => $this->userId,
                'action' => 'finalize_completed',
                'details' => 'Finalize completed successfully',
                'metadata' => [
                    'processed_files' => $processedFiles,
                    'total_files' => $totalFiles,
                    'completed_at' => now()->toISOString(),
                    'result_status' => Shoot::STATUS_DELIVERED,
                    'final_status' => $this->finalStatus,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Finalize job failed', [
                'shoot_id' => $this->shootId,
                'user_id' => $this->userId,
                'processed_files' => $processedFiles,
                'error' => $e->getMessage(),
            ]);

            if ($shoot) {
                $shoot->workflowLogs()->create([
                    'user_id' => $this->userId,
                    'action' => 'finalize_failed',
                    'details' => 'Finalize processing failed',
                    'metadata' => [
                        'processed_files' => $processedFiles,
                        'final_status' => $this->finalStatus,
                        'failed_at' => now()->toISOString(),
                        'error' => $e->getMessage(),
                    ],
                ]);
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
