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
        $plan = $this->authority()->plan($step); app(LabMachineSegmentationPlan::class)->validate($plan, $step->request_id);
        $this->priorFloor($step, $plan);
        [$bytes, $binding] = $this->input($step, $plan); $payload = app(LabMachineSegmentationPlan::class)->payload($plan, $bytes); unset($bytes);
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
        if ($created) try { ProcessIguideLabMachineSegmentation::dispatch($record->id); }
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
            $data = $record->data; $plan = $data['plan']; app(LabMachineSegmentationPlan::class)->validate($plan, $record->request_id);
            app(LabStorage::class)->prepareMachineSegmentation($record->project_id, $record->id);
            $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$record->project_id.'/machine-segmentations/'.$record->id;
            $transport = app(LabMachineSegmentationTransport::class);
            if (! $record->provider_request_id) {
                if ($record->status !== 'queued') { $this->stop($record, 'The previous paid submission is unconfirmed and will not be repeated.'); return true; }
                abort_unless($this->authority()->plan($step) === $plan, 422, 'The machine assessment changed.'); $this->priorFloor($step,$plan);
                [$bytes,$binding] = $this->input($step,$plan); abort_unless($binding === $data['assetBinding'], 422, 'Segmentation input binding changed.');
                $payload = app(LabMachineSegmentationPlan::class)->payload($plan,$bytes); unset($bytes);
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
                try { $descriptor=app(LabMachineMaskPixels::class)->describe($bytes); $descriptors[]=$descriptor; if ($descriptor['positivePixels']===0) $invalid=true; }
                catch (\InvalidArgumentException) { $invalid=true; }
            }
            $receipt = ['requestId'=>$record->request_id,'providerRequestId'=>$record->provider_request_id,'model'=>LabMachineSegmentationTransport::MODEL,
                'inputFingerprint'=>$plan['inputFingerprint'],'inputSha256'=>$plan['inputSha256'],'rawResponseSha256'=>$responseHash,
                'status'=>$invalid?'needs_review':'completed','masks'=>$descriptors];
            $association = $invalid ? ['status'=>'needs_review','reviewReasons'=>['MASK_COUNT_DIMENSIONS_OR_PIXELS_INVALID'],'associations'=>[]]
                : $this->authority()->associate($step,$receipt);
            $ready = ($association['status'] ?? null) === 'ready_for_support_validation';
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

    private function input(IguideLabWorkflowStep $step,array $plan): array
    {
        abort_unless(($step->input['protocolVersion'] ?? null)===self::PROTOCOL,422,'Unsupported segmentation protocol.');
        $item=IguideLabWorkflowItem::where('project_id',$step->project_id)->findOrFail($step->item_id);
        $native=app(LabWorkflowSources::class)->verify($step->project_id,$item->source);
        $section=IguideLabSection::where('project_id',$step->project_id)->where('status','completed')->findOrFail($step->input['sectionId'] ?? '');
        abort_unless($section->case_id===$native->id,422,'Section belongs to another capture.');
        $case=IguideLabCase::where('project_id',$step->project_id)->findOrFail($section->derived_case_id);
        $snapshot=app(LabMasks::class)->sectionSnapshot($step->project_id,$section->id,$case,null);
        $run=IguideLabRun::where('project_id',$step->project_id)->where('case_id',$case->id)->where('status','completed')->findOrFail($step->input['candidateRunId'] ?? '');
        abort_unless($case->projection==='rectilinear' && [$case->width,$case->height,$run->output_width,$run->output_height]===[2048,2048,2048,2048]
            && ($run->options['sourceSection'] ?? null)===$snapshot && app(LabOutputDigests::class)->dto($run)!==null,422,'Candidate section provenance is unavailable.');
        $source=$this->ownedBytes($step->project_id,$case->source_path,'cases/'.$case->id.'.png',$case->source_sha256);
        $candidate=$this->ownedBytes($step->project_id,$run->output_path,'runs/'.$run->id.'.png',$run->output_sha256);
        $submitted=hash('sha256',LabImages::prepared($source));
        abort_unless(($run->options['preparedInputSha256'][0] ?? null)===$submitted,422,'The exact generation source preparation changed.');
        $bytes=$plan['stepKey']==='source-floor'?$source:$candidate;
        abort_unless(hash_equals($plan['inputSha256'],hash('sha256',$bytes)),422,'The selected segmentation source changed.');
        return [$bytes,['nativeSource'=>$item->source,'section'=>$snapshot,'caseId'=>$case->id,'candidateRunId'=>$run->id,
            'sourceCutSha256'=>$case->source_sha256,'submittedSourceSha256'=>$submitted,'candidateSha256'=>$run->output_sha256]];
    }
    private function ownedBytes(string $projectId,string $path,string $suffix,string $sha): string
    {
        $disk=Storage::disk(LabService::DISK); $expected='iguide-lab/'.$projectId.'/'.$suffix;
        $root=realpath($disk->path('iguide-lab')); $file=$disk->path($path); $real=realpath($file);
        abort_unless($path===$expected && $root && $real && $real===$root.DIRECTORY_SEPARATOR.$projectId.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$suffix)
            && !is_link($file) && filesize($real)>0 && filesize($real)<=30*1024*1024,422,'Segmentation input is outside owned storage.');
        $bytes=file_get_contents($real); abort_unless(hash_equals($sha,hash('sha256',$bytes)),422,'Segmentation input bytes changed.');
        $size=@getimagesizefromstring($bytes); abort_unless($size && ($size['mime'] ?? null)==='image/png' && [$size[0],$size[1]]===[2048,2048],422,'Stored segmentation input dimensions changed.');
        $decoded=@imagecreatefromstring($bytes); abort_unless($decoded!==false,422,'Stored segmentation input cannot be decoded.'); imagedestroy($decoded);
        return $bytes;
    }
    private function priorFloor(IguideLabWorkflowStep $step,array $plan): void
    {
        if ($plan['stepKey']!=='candidate-furniture') return;
        $ids=IguideLabWorkflowStep::where('project_id',$step->project_id)->where('item_id',$step->item_id)->where('kind','segmentation')->pluck('id');
        $found=IguideLabMachineSegmentation::whereIn('step_id',$ids)->where('status','completed')->get()->first(fn($r)=>($r->data['plan']['stepKey'] ?? null)==='source-floor'
            && ($r->data['plan']['contextSha256'] ?? null)===$plan['contextSha256'] && ($r->data['plan']['assessmentProposalSha256'] ?? null)===$plan['assessmentProposalSha256']
            && ($r->result['association']['status'] ?? null)==='ready_for_support_validation');
        abort_unless($found,422,'The original-floor receipt must pass before spending the furniture request.');
        $disk=Storage::disk(LabService::DISK); $artifacts=$found->result['artifacts'] ?? [];
        abort_unless(count($artifacts)===1,422,'The original-floor artifact is unavailable.');
        foreach ($artifacts as $artifact) {
            $expected='iguide-lab/'.$step->project_id.'/machine-segmentations/'.$found->id.'/mask-0.png';
            abort_unless(($artifact['path'] ?? null)===$expected && $disk->exists($expected) && filesize($disk->path($expected))<=LabMachineSegmentationTransport::MAX_MASK_BYTES,422,'The original-floor mask is unavailable.');
            $this->ownedBytes($step->project_id,$expected,'machine-segmentations/'.$found->id.'/mask-0.png',$artifact['sha256']);
        }
    }
    private function authority(): LabMachineSegmentationAuthority
    {
        abort_unless(app()->bound(LabMachineSegmentationAuthority::class),503,'Persisted machine assessment is not integrated.');
        return app(LabMachineSegmentationAuthority::class);
    }
    public function stop(IguideLabMachineSegmentation $record,string $message,string $status='needs_attention'): void
    {
        LockedWrite::run(fn()=>IguideLabMachineSegmentation::whereKey($record->id)->whereNotIn('status',['completed','needs_review'])
            ->update(['status'=>$status,'error'=>$message,'completed_at'=>now(),'updated_at'=>now()]),'iguide-lab.machine-segmentation.stop');
    }
    private function save(IguideLabMachineSegmentation $record,array $values): void { LockedWrite::run(fn()=> $record->update($values),'iguide-lab.machine-segmentation.state'); }
}
