<?php
namespace Tests\Feature;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabProject};
use App\Services\IguideLab\{LabAnalysisRecipes,LabLifestyleOpticalCutProtocol,LabService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\IguideLabLifestyleOpticalCutProtocolTest;

/** Authenticated read boundary; synthetic records are not provider/semantic evidence. */
class IguideLabLifestyleOpticalAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK));
        [$context,$images]=IguideLabLifestyleOpticalCutProtocolTest::fixture();
        $token=str_repeat('a',64);
        $project=IguideLabProject::create(['name'=>'Synthetic native asset scope','token_hash'=>hash('sha256',$token),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $case=IguideLabCase::create(['project_id'=>$project->id,'label'=>'Synthetic cut','projection'=>'rectilinear','width'=>2048,'height'=>2048,
            'source_sha256'=>$context['sourceSha256'],'source_path'=>'unused.png','source_mime'=>'image/png']);
        $analysis=IguideLabAnalysis::create(['project_id'=>$project->id,'case_id'=>$case->id,'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('1',64),
            'source_sha256'=>$case->source_sha256,'settings'=>['viewRecipe'=>LabLifestyleOpticalCutProtocol::VIEW_RECIPE],'status'=>'completed']);
        $base='iguide-lab/'.$project->id.'/analyses/'.$analysis->id.'/';$views=[];
        foreach($context['sourceContext']['views'] as $v)$views[]=$v+['path'=>$base.$v['id'].'.png'];
        foreach($context['lifestyleFraming']['opticalDetails']['views'] as $v)$views[]=['id'=>$v['id'],'path'=>$base.$v['id'].'.png','sha256'=>$v['sha256'],
            'width'=>2048,'height'=>2048,'projection'=>'rectilinear','role'=>'same_capture_native_detail','yaw'=>$v['pose']['yaw'],'pitch'=>$v['pose']['pitch'],'hFov'=>$v['pose']['hFov']];
        foreach($images as $i=>$image)Storage::disk(LabService::DISK)->put($views[$i]['path'],base64_decode(explode(',',$image,2)[1]));
        $analysis->update(['data'=>['executionRecipe'=>app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_OPTICAL_CUT),
            'lifestyleFraming'=>$context['lifestyleFraming'],'sourceContext'=>$context['sourceContext'],'sourceContextManifest'=>$context['sourceContext'],
            'sourceContextManifestSha256'=>LabAnalysisRecipes::hash($context['sourceContext']),'views'=>$views]]);
        return [$analysis,$token,$base];
    }

    public function test_authenticated_owner_gets_exact_native_bytes_with_private_headers(): void
    {
        [$a,$token]=$this->fixture();
        foreach(['source_detail_1','source_detail_2'] as $i=>$id){$r=$this->withToken($token)->get('/api/iguide-lab/analyses/'.$a->id.'/views/'.$id);
            $r->assertOk()->assertHeader('Content-Type','image/png');
            $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));
            $this->assertSame($a->data['views'][6+$i]['sha256'],hash_file('sha256',$r->baseResponse->getFile()->getPathname()));}
    }

    public function test_other_project_missing_auth_and_unknown_ids_cannot_read(): void
    {
        [$a,$token]=$this->fixture();$other=str_repeat('b',64);
        IguideLabProject::create(['name'=>'Other scope','token_hash'=>hash('sha256',$other),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $url='/api/iguide-lab/analyses/'.$a->id.'/views/';
        $this->getJson($url.'source_detail_1')->assertUnauthorized();
        $this->withToken($other)->getJson($url.'source_detail_1')->assertNotFound();
        $this->withToken($token)->getJson($url.'source_detail_3')->assertNotFound();
    }

    public function test_changed_descriptor_reference_or_bytes_are_held(): void
    {
        [$a,$token]=$this->fixture();$data=$a->data;$url='/api/iguide-lab/analyses/'.$a->id.'/views/source_detail_1';
        foreach([fn($d)=>array_replace_recursive($d,['views'=>[6=>['yaw'=>61]]]),
            fn($d)=>array_replace_recursive($d,['views'=>[6=>['width'=>1024]]]),
            fn($d)=>array_replace_recursive($d,['lifestyleFraming'=>['opticalDetails'=>['views'=>[0=>['sha256'=>str_repeat('0',64)]]]]]) ] as $change){
            $a->update(['data'=>$change($data)]);$this->withToken($token)->getJson($url)->assertUnprocessable();}
        $a->update(['data'=>$data]);Storage::disk(LabService::DISK)->put($data['views'][6]['path'],'modified');
        $this->withToken($token)->getJson($url)->assertUnprocessable();
    }

    public function test_unknown_or_legacy_recipe_cannot_borrow_native_read_authority(): void
    {
        [$a,$token]=$this->fixture();$data=$a->data;$url='/api/iguide-lab/analyses/'.$a->id.'/views/source_detail_1';
        $bad=$data;$bad['executionRecipe']['compilerVersion']='unknown';$a->update(['data'=>$bad]);
        $this->withToken($token)->getJson($url)->assertNotFound();
        $bad=$data;$bad['executionRecipe']=app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_CUT);$a->update(['data'=>$bad]);
        $this->withToken($token)->getJson($url)->assertNotFound();
    }
}
