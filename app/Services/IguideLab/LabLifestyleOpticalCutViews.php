<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabWorkflowItem};

/** Exact native evidence is forwarded, never resized from a prepared panorama. */
class LabLifestyleOpticalCutViews
{
    public function prepare(IguideLabAnalysis $analysis,IguideLabCase $cut,array $snapshot): array
    {
        $reference=$analysis->data['lifestyleFraming'];
        app(LabLifestyleCutReadiness::class)->verifyReference($analysis->data['workflowInvocation'],$cut,$reference);
        $prepared=app(LabSectionAnalysisViews::class)->prepare($analysis,$cut,$snapshot);
        $item=IguideLabWorkflowItem::where('project_id',$analysis->project_id)->findOrFail($analysis->data['workflowInvocation']['itemId']);
        $framing=app(LabLifestyleFramings::class)->completed($item,$reference['id']);$bundle=$framing->data['contextSnapshot'];
        $files=app(LabLifestyleFramingContext::class);$base='iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id;
        foreach($reference['opticalDetails']['views'] as $i=>$v){$asset=$bundle['assets'][7+$i];
            abort_unless($asset['id']===$v['id']&&$asset['sha256']===$v['sha256'],422,'Native context asset association changed.');
            $bytes=file_get_contents($files->file($analysis->project_id,$asset['path'],$v['sha256'],16*1024*1024));
            $path=$base.'/'.$v['id'].'.png';$files->put($path,$bytes);
            $prepared['views'][]=['id'=>$v['id'],'path'=>$path,'sha256'=>$v['sha256'],'width'=>2048,'height'=>2048,'projection'=>'rectilinear',
                'role'=>'same_capture_native_detail','yaw'=>$v['pose']['yaw'],'pitch'=>$v['pose']['pitch'],'hFov'=>$v['pose']['hFov']];}
        return $prepared;
    }
    public function verifyStored(IguideLabAnalysis $analysis): void
    {
        $data=$analysis->data;$reference=$data['lifestyleFraming'];$expected=$reference['opticalDetails']['views'];$views=$data['views']??[];
        abort_unless(array_column($views,'id')===[...LabAnalysisSourceContext::IDS,...array_column($expected,'id')],422,'Native context set changed.');
        $base=clone $analysis;$baseData=$data;$baseData['views']=array_slice($views,0,6);$base->setAttribute('data',$baseData);
        app(LabSectionAnalysisViews::class)->verifyStored($base);
        foreach($expected as $i=>$v){$actual=$views[6+$i];$path='iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id.'/'.$v['id'].'.png';
            $descriptor=['id'=>$v['id'],'path'=>$path,'sha256'=>$v['sha256'],'width'=>2048,'height'=>2048,'projection'=>'rectilinear',
                'role'=>'same_capture_native_detail','yaw'=>$v['pose']['yaw'],'pitch'=>$v['pose']['pitch'],'hFov'=>$v['pose']['hFov']];
            abort_unless(LabMachineSegmentationPlan::canonicalHash($actual)===LabMachineSegmentationPlan::canonicalHash($descriptor),422,'Native detail descriptor changed.');
            $file=app(LabLifestyleFramingContext::class)->file($analysis->project_id,$path,$v['sha256'],16*1024*1024);$size=getimagesize($file);
            abort_unless([$size[0],$size[1],$size['mime']] === [2048,2048,'image/png'],422,'Native detail image changed.');}
    }
}
