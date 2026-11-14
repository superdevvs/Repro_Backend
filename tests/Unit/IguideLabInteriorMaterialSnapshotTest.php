<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabInteriorDesignMaterials,LabMachineSegmentationPlan};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
class IguideLabInteriorMaterialSnapshotTest extends TestCase
{
    public function test_composite_key_canonicalization_preserves_snapshot_but_code_drift_does_not(): void
    {
        $adapter=new class extends LabInteriorDesignMaterials{
            public function ready():bool{return true;}
            public function snapshot():array{return ['version'=>'fixture','files'=>['worker'=>'a','core'=>'b'],'pythonPath'=>'/private/python','pythonExecutableSha256'=>str_repeat('c',64)];}
        };
        $snapshot=$adapter->snapshot();$serialized=['files'=>['core'=>'b','worker'=>'a'],'pythonExecutableSha256'=>str_repeat('c',64),'pythonPath'=>'/private/python','version'=>'fixture'];
        $this->assertNotSame(json_encode($snapshot),json_encode($serialized));
        $adapter->validate($serialized);$this->assertSame(LabMachineSegmentationPlan::canonicalHash($snapshot),LabMachineSegmentationPlan::canonicalHash($serialized));
        $serialized['files']['worker']='changed';$this->expectException(HttpException::class);$adapter->validate($serialized);
    }
}
