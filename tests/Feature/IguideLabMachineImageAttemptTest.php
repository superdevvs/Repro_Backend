<?php

namespace Tests\Feature;

use App\Models\IguideLabProject;
use App\Services\IguideLab\{LabDeclutterImageDraft,LabProvider,LabService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IguideLabMachineImageAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake(); Http::preventStrayRequests();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)), 'services.openai.api_key'=>'fixture-fallback']);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        $disk=Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK)); $disk->makeDirectory('');
        $disk->put('test-primary.key','fixture-primary'); config(['iguide-lab.openai_primary_key_path'=>$disk->path('test-primary.key')]);
        $project=IguideLabProject::create(['name'=>'Per-attempt authority','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $bytes=(string)\Intervention\Image\ImageManager::gd()->create(2048,2048)->fill('888888')->toPng();
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('cut.png',$bytes),['label'=>'Original cut','projection'=>'rectilinear']);
        $run=app(LabService::class)->start($project,['caseId'=>$case->id,'requestId'=>(string)Str::uuid(),'modelId'=>'gpt-image-2.6-sunburst','preset'=>'declutter']);
        // Isolate the transport boundary; full typed authority is exercised in
        // the exact-worker chain. The authority here is a mutable retained file.
        $run->update(['options'=>$run->options+['sourceMachinePlan'=>['version'=>'machine-removal-plan/1']]]);
        $disk->put('authority-receipt.json','retained');
        $this->mock(LabDeclutterImageDraft::class,function($mock)use($run,$case,$disk){
            $mock->shouldReceive('verifyRun')->withArgs(fn($actual,$source)=>$actual->id===$run->id&&$source->id===$case->id)
                ->andReturnUsing(function()use($disk){abort_unless($disk->exists('authority-receipt.json'),422,'Machine authority changed.');});
        });
        return [$run,$bytes,$disk];
    }

    public function test_missing_machine_authority_prevents_first_provider_claim(): void
    {
        [$run,$bytes,$disk]=$this->fixture(); $disk->delete('authority-receipt.json');
        try { app(LabProvider::class)->submit($run,[$bytes]); $this->fail('Missing authority reached provider.'); }
        catch(HttpException $error){$this->assertSame(422,$error->getStatusCode());}
        Http::assertNothingSent(); $this->assertSame([], $run->fresh()->options['providerAttempts']??[]);
    }

    public function test_authority_drift_after_quota_rejection_prevents_fallback(): void
    {
        [$run,$bytes,$disk]=$this->fixture();
        Http::fake(function()use($disk){$disk->delete('authority-receipt.json');return Http::response(['error'=>['code'=>'insufficient_quota']],429,['x-request-id'=>'quota-only']);});
        try { app(LabProvider::class)->submit($run,[$bytes]); $this->fail('Stale machine authority reached fallback.'); }
        catch(HttpException $error){$this->assertSame(422,$error->getStatusCode());}
        Http::assertSentCount(1); $this->assertSame(['quota_rejected'],array_column($run->fresh()->options['providerAttempts'],'status'));
    }

    public function test_unchanged_machine_authority_allows_only_explicit_quota_fallback(): void
    {
        [$run,$bytes]=$this->fixture();
        Http::fakeSequence('api.openai.com/v1/images/edits')->push(['error'=>['code'=>'insufficient_quota']],429)
            ->push(['data'=>[['b64_json'=>base64_encode($bytes)]]],200,['x-request-id'=>'valid-fallback']);
        $result=app(LabProvider::class)->submit($run,[$bytes]);
        $this->assertSame('valid-fallback',$result['requestId']); Http::assertSentCount(2);
        $this->assertSame(['quota_rejected','received'],array_column($run->fresh()->options['providerAttempts'],'status'));
    }
}
