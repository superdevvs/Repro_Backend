<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabLightingD2, LabWorkflowArtifacts};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LabLightingD2Test extends TestCase
{
    private array $temporary = [];
    protected function tearDown(): void { foreach ($this->temporary as $p) @unlink($p); parent::tearDown(); }
    private function manifest(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'd2-test-'); $this->temporary[] = $path;
        config(['iguide-lab.lighting_d2_enabled' => true, 'iguide-lab.lighting_d2_runtime_manifest' => $path, 'iguide-lab.pipeline_python_path' => PHP_BINARY]);
        $snapshot = LabLightingD2::snapshot();
        // Synthetic guard fixture, not proof that PHP_BINARY is a Python runtime.
        $m = ['validationStatus' => 'passed', 'recipeVersion' => LabLightingD2::VERSION, 'pythonPath' => PHP_BINARY,
            'pythonVersion' => '3.12.3', 'numpyVersion' => '2.5.4', 'pillowVersion' => '13.0.0',
            'authoritySha256' => $snapshot['authoritySha256'], 'files' => $snapshot['files']];
        file_put_contents($path, json_encode($m, JSON_THROW_ON_ERROR));
        return [$path, $m];
    }
    public function test_default_snapshot_keeps_the_old_recipe_and_does_not_select_d2(): void
    {
        $this->assertFalse(LabLightingD2::enabled());
        $snapshot = app(LabWorkflowArtifacts::class)->snapshot();
        $this->assertSame(LabWorkflowArtifacts::VERSION, $snapshot['recipeVersion']);
        $this->assertArrayNotHasKey('lightingD2', $snapshot);
    }
    public function test_enabled_flag_requires_a_validated_manifest_before_any_processing(): void
    {
        config(['iguide-lab.lighting_d2_enabled' => true, 'iguide-lab.lighting_d2_runtime_manifest' => null]);
        $this->expectException(HttpException::class);
        LabLightingD2::validateRuntime(LabLightingD2::snapshot());
    }
    public function test_reserved_snapshot_accepts_canonical_storage_order_but_rejects_drift(): void
    {
        $this->manifest(); $adapter=app(LabWorkflowArtifacts::class);$snapshot=$adapter->snapshot();
        $snapshot['lightingD2']['files']=array_reverse($snapshot['lightingD2']['files'],true);
        $adapter->validateSnapshot($snapshot);LabLightingD2::validateRuntime($snapshot['lightingD2']);$this->addToAssertionCount(2);
        $snapshot['lightingD2']['files']['core']=str_repeat('0',64);
        $this->expectException(HttpException::class);$adapter->validateSnapshot($snapshot);
    }
    public function test_manifest_and_worker_provenance_drift_are_rejected(): void
    {
        [$path,$m]=$this->manifest();$snapshot=LabLightingD2::snapshot();
        $report=['recipe'=>LabLightingD2::VERSION,'runtime'=>['lightingD2Recipe'=>LabLightingD2::VERSION,
            'lightingD2CodeSha256'=>$snapshot['files']['core'],'lightingD2WorkerSha256'=>$snapshot['files']['worker']]];
        LabLightingD2::verifyReport($report,$snapshot);$this->addToAssertionCount(1);
        foreach (['lightingD2Recipe','lightingD2CodeSha256','lightingD2WorkerSha256'] as $key) {
            $bad=$report;$bad['runtime'][$key]='changed';
            try { LabLightingD2::verifyReport($bad,$snapshot);$this->fail('Drift accepted: '.$key); } catch(HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        }
        $m['files']['core']=str_repeat('0',64);file_put_contents($path,json_encode($m,JSON_THROW_ON_ERROR));
        try { LabLightingD2::validateRuntime($snapshot);$this->fail('Reserved manifest drift accepted'); } catch(HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        try { LabLightingD2::validateRuntime(LabLightingD2::snapshot());$this->fail('Unvalidated code accepted'); } catch(HttpException $e) { $this->assertSame(503,$e->getStatusCode()); }
    }
}
