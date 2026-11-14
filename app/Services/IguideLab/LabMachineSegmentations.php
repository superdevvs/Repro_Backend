<?php

namespace App\Services\IguideLab;

use App\Contracts\LabMachineSegmentationAuthority;
use App\Jobs\ProcessIguideLabMachineSegmentation;
use App\Models\IguideLabCase;
use App\Models\IguideLabMachineSegmentation;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Models\IguideLabSection;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Internal-only machine support. No mask reviewed_at, public start route or acceptance. */
class LabMachineSegmentations
{
    public const PROTOCOL = 'automatic-staging-segmentation/1';

    public function start(IguideLabWorkflowStep $step): IguideLabMachineSegmentation
    {
        $invocation = app(LabWorkflowSteps::class)->invocation($step);
        app(LabWorkflowInvocation::class)->verifyInput($invocation, $step->project_id, ['requestId' => $step->request_id] + $step->input, 'segmentation');
        $plan = $this->authority($step)->plan($step); $this->capability($step)->validatePlan($plan, $step->request_id);
        $this->capability($step)->prerequisite($step, $plan);
        [$bytes, $binding] = $this->capability($step)->verifyOwnedInputs($step, $plan); $payload = $this->capability($step)->payload($plan, $bytes); unset($bytes);
        abort_unless(app(LabMachineSegmentationTransport::class)->ready(), 503, 'Segmentation is unavailable.');
        $data = ['workflowInvocation' => $invocation, 'plan' => $plan, 'assetBinding' => $binding, 'providerBodySha256' => LabAnalysisRecipes::hash($payload)]; unset($payload);
        [$record,$created] = LockedWrite::run(fn () => DB::transaction(function () use ($step,$data,$invocation) {
            IguideLabProject::whereKey($step->project_id)->update(['updated_at' => now()]);
            app(LabWorkflowInvocation::class)->verify($invocation, $step->project_id, $step->request_id, 'segmentation', false);
            if ($prior = IguideLabMachineSegmentation::where('project_id', $step->project_id)->where('request_id', $step->request_id)->first()) {
                abort_unless($prior->step_id === $step->id && hash_equals($prior->request_hash, $step->input_sha256) && $prior->data === $data, 409, 'Segmentation reservation changed.');
                return [$prior,false];
            }
            return [IguideLabMachineSegmentation::create(['project_id' => $step->project_id, 'step_id' => $step->id,
                'request_id' => $step->request_id, 'request_hash' => $step->input_sha256, 'status' => 'queued', 'data' => $data]),true];
        }), 'iguide-lab.machine-segmentation.reserve');
        app(LabWorkflowSteps::class)->attach($step, $record->id);
        // Recover a crash after the record commit but before attachment/dispatch.
        // A submission checkpoint or known receipt is never queued by start().
        $record->refresh();
        if ($record->status === 'queued' && $record->provider_request_id === null
            && empty($record->provider_attempts) && $record->started_at === null) try { ProcessIguideLabMachineSegmentation::dispatch($record->id); }
        catch (Throwable) { $this->stop($record, 'The queue reservation is unconfirmed. No paid request was repeated.'); }
        return $record->fresh();
    }

    public function process(IguideLabMachineSegmentation $record): bool
    {
        $record->refresh(); if (in_array($record->status, ['completed','needs_review','needs_attention','failed'], true)) return true;
        $polling = false;
        try {
            $step = IguideLabWorkflowStep::where('project_id', $record->project_id)->findOrFail($record->step_id);
            abort_unless($step->request_id === $record->request_id && $step->input_sha256 === $record->request_hash
                && hash_equals($step->input_sha256, LabAnalysisRecipes::hash($step->input)), 422, 'Segmentation step changed.');
            $data = $record->data; $plan = $data['plan']; $this->capability($step)->validatePlan($plan, $record->request_id);
            app(LabStorage::class)->prepareMachineSegmentation($record->project_id, $record->id);
            $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$record->project_id.'/machine-segmentations/'.$record->id;
            $transport = app(LabMachineSegmentationTransport::class);
            if (! $record->provider_request_id) {
                if ($record->status !== 'queued') { $this->stop($record, 'The previous paid submission is unconfirmed and will not be repeated.'); return true; }
                abort_unless($this->authority($step)->plan($step) === $plan, 422, 'The machine assessment changed.'); $this->capability($step)->prerequisite($step,$plan);
                [$bytes,$binding] = $this->capability($step)->verifyOwnedInputs($step,$plan); abort_unless($binding === $data['assetBinding'], 422, 'Segmentation input binding changed.');
                $payload = $this->capability($step)->payload($plan,$bytes); unset($bytes);
                abort_unless(hash_equals($data['providerBodySha256'], LabAnalysisRecipes::hash($payload)), 422, 'Segmentation provider payload changed.');
                $attempt = ['credentialSource' => 'dashboard-fal', 'model' => LabMachineSegmentationTransport::MODEL,
                    'status' => 'submitting','providerRequestId' => null,'httpStatus' => null,'startedAt' => now()->toIso8601String(),'completedAt' => null];
                $claimed = app(LabWorkflowInvocation::class)->claim($data['workflowInvocation'], $record->project_id, $record->request_id, 'segmentation',
                    fn () => IguideLabMachineSegmentation::whereKey($record->id)->where('status','queued')->whereNull('provider_request_id')
                        ->update(['status' => 'submitting','provider_attempts' => json_encode([$attempt], JSON_THROW_ON_ERROR),'started_at' => now(),'updated_at' => now()]));
                if (! $claimed) { $this->stop($record, 'A previous submission claim exists; no second paid request was sent.'); return true; }
                $started = hrtime(true); $receipt = $transport->submit($payload); unset($payload);
                $attempt = array_merge($attempt, ['status' => $receipt['status'],'providerRequestId' => $receipt['providerRequestId'],
                    'httpStatus' => $receipt['httpStatus'],'completedAt' => now()->toIso8601String(),'durationMs' => (int) ((hrtime(true)-$started)/1e6),
                    'responseSha256' => $receipt['responseSha256']]);
                // Persist receipt BEFORE disk operations, so storage failure never loses a known paid ID.
                $this->save($record, ['provider_attempts' => [$attempt], 'provider_request_id' => $receipt['providerRequestId'],
                    'status' => $receipt['status'] === 'received' ? 'processing' : ($receipt['status'] === 'rejected' ? 'failed' : 'needs_attention')]);
                if ($receipt['raw'] !== null) $disk->put($base.'/submission-response.json', $receipt['raw'], ['visibility' => 'private']);
                if ($receipt['status'] !== 'received') { $this->stop($record, 'The provider submission was rejected or unconfirmed. No automatic retry is permitted.', $receipt['status'] === 'rejected' ? 'failed' : 'needs_attention'); return true; }
            }
            if ($record->started_at?->lt(now()->subMinutes(30))) { $this->stop($record, 'The retained segmentation receipt exceeded its polling budget.'); return true; }
            $polling = true; $result = $transport->poll($record->provider_request_id);
            if ($result === null) return false; $polling = false;
            $disk->put($base.'/provider-result.json', $result['raw'], ['visibility' => 'private']);
            $responseHash = hash('sha256',$result['raw']);
            if ($result['status'] !== 'completed') { $this->stop($record, 'The provider ended segmentation without usable results.', 'failed'); return true; }
            $masks = $result['result']['masks'] ?? null;
            abort_unless(is_array($masks) && array_is_list($masks) && count($masks) <= $plan['payload']['max_masks'], 422, 'Provider returned an unsupported mask set.');
            $descriptors = []; $artifacts = []; $invalid = count($masks) !== count($plan['expectedIdentities']);
            foreach ($masks as $index => $mask) {
                abort_unless(is_array($mask) && is_string($mask['url'] ?? null), 422, 'Invalid mask asset descriptor.');
                $polling = true; $bytes = $transport->mask($mask['url']); $polling = false;
                $path = $base.'/mask-'.$index.'.png'; $disk->put($path,$bytes,['visibility'=>'private']);
                $artifacts[] = ['index'=>$index,'path'=>$path,'sha256'=>hash('sha256',$bytes)];
                try { $descriptor=$this->capability($step)->describeMask($bytes); $descriptors[]=$descriptor; if ($descriptor['positivePixels']===0) $invalid=true; }
                catch (\InvalidArgumentException) { $invalid=true; }
            }
            $receipt = ['requestId'=>$record->request_id,'providerRequestId'=>$record->provider_request_id,'model'=>LabMachineSegmentationTransport::MODEL,
                'inputFingerprint'=>$plan['inputFingerprint'],'inputSha256'=>$plan['inputSha256'],'rawResponseSha256'=>$responseHash,
                'status'=>$invalid?'needs_review':'completed','masks'=>$descriptors];
            if (! $invalid) {
                // A known receipt is GET-only recovery, not permission to accept stale support.
                abort_unless($this->authority($step)->plan($step) === $plan, 422, 'The machine assessment changed while segmentation was pending.');
                [$currentBytes, $currentBinding] = $this->capability($step)->verifyOwnedInputs($step, $plan); unset($currentBytes);
                abort_unless($currentBinding === $data['assetBinding'], 422, 'The segmentation source changed while its receipt was pending.');
            }
            $association = $invalid ? ['status'=>'needs_review','reviewReasons'=>['MASK_COUNT_DIMENSIONS_OR_PIXELS_INVALID'],'associations'=>[]]
                : $this->authority($step)->associate($step,$receipt);
            $ready = ($association['status'] ?? null) === $this->capability($step)->readyAssociationStatus();
            $this->save($record,['status'=>$ready?'completed':'needs_review','result'=>['receipt'=>$receipt,'association'=>$association,'artifacts'=>$artifacts,
                'maskOrigin'=>'model_segmentation','selectionOrigin'=>'machine','humanReviewed'=>false,'automaticAcceptance'=>false,
                'usage'=>is_array($result['result']['usage'] ?? null)?$result['result']['usage']:null,'costUsd'=>null], 'completed_at'=>now(), 'error'=>$ready?null:'Machine support requires review.']);
        } catch (Throwable $error) {
            $record->refresh(); if ($polling && ! $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                && $record->provider_request_id && $record->status==='processing' && $record->started_at?->gt(now()->subMinutes(30))) return false;
            $this->stop($record,'Segmentation binding, transport or support verification needs review. No paid retry will be sent.');
        }
        return true;
    }

    private function authority(IguideLabWorkflowStep $step): LabMachineSegmentationAuthority
    {
        return app()->bound(LabMachineSegmentationAuthority::class) ? app(LabMachineSegmentationAuthority::class) : $this->capability($step);
    }
    private function capability(IguideLabWorkflowStep $step): \App\Contracts\LabMachineSegmentationCapability
    {
        return app(LabMachineSegmentationCapabilities::class)->forStep($step);
    }
    public function stop(IguideLabMachineSegmentation $record,string $message,string $status='needs_attention'): void
    {
        LockedWrite::run(fn()=>IguideLabMachineSegmentation::whereKey($record->id)->whereNotIn('status',['completed','needs_review'])
            ->update(['status'=>$status,'error'=>$message,'completed_at'=>now(),'updated_at'=>now()]),'iguide-lab.machine-segmentation.stop');
    }
    private function save(IguideLabMachineSegmentation $record,array $values): void { LockedWrite::run(fn()=> $record->update($values),'iguide-lab.machine-segmentation.state'); }
}
