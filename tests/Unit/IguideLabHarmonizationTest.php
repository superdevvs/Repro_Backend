<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabHarmonization;
use RuntimeException;
use Tests\TestCase;

class IguideLabHarmonizationTest extends TestCase
{
    private string $python;
    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->python = tempnam(sys_get_temp_dir(), 'harmonic-exec-');
        $this->manifest = tempnam(sys_get_temp_dir(), 'harmonic-runtime-');
        file_put_contents($this->python, "#!/bin/sh\nexit 1\n"); chmod($this->python, 0700);
        file_put_contents($this->manifest, json_encode(['schemaVersion' => 1, 'validationStatus' => 'passed', 'pythonPath' => $this->python,
            'pythonVersion' => '3.12.3', 'numpyVersion' => '2.5.4', 'pillowVersion' => '13.0.0', 'scipyVersion' => '1.18.1',
            'validatedSolverSha256' => hash_file('sha256', resource_path('iguide-lab/section_harmonization.py')),
            'validatedAdapterSha256' => hash_file('sha256', resource_path('iguide-lab/harmonize_section.py'))]));
        config(['iguide-lab.harmonization_python_path' => $this->python, 'iguide-lab.harmonization_runtime_manifest' => $this->manifest]);
    }

    protected function tearDown(): void
    {
        unlink($this->python); unlink($this->manifest); parent::tearDown();
    }

    public function test_snapshot_binds_exact_runtime_manifest_and_source_cut(): void
    {
        $service = app(LabHarmonization::class); $this->assertTrue($service->ready());
        $snapshot = $service->snapshot(str_repeat('a', 64)); $service->validate($snapshot);
        $this->assertSame(hash_file('sha256', $this->manifest), $snapshot['runtimeManifestSha256']);
        $this->assertSame(hash_file('sha256', resource_path('iguide-lab/section_harmonization.py')), $snapshot['solverSha256']);
        file_put_contents($this->manifest, "\n", FILE_APPEND);
        $this->expectException(RuntimeException::class); $service->validate($snapshot);
    }

    public function test_unvalidated_or_wrong_runtime_is_not_ready(): void
    {
        $manifest = json_decode(file_get_contents($this->manifest), true);
        foreach ([['validationStatus' => 'pending'], ['scipyVersion' => 'other'], ['pythonPath' => '/another/python'],
            ['validatedSolverSha256' => str_repeat('f', 64)], ['validatedAdapterSha256' => str_repeat('f', 64)]] as $changes) {
            file_put_contents($this->manifest, json_encode(array_replace($manifest, $changes)));
            $this->assertFalse(app(LabHarmonization::class)->ready());
        }
    }

    public function test_execution_rejects_incomplete_terminal_report_and_nonzero_exit(): void
    {
        $service = app(LabHarmonization::class); $snapshot = $service->snapshot(str_repeat('a', 64));
        $base = ['recipe' => $snapshot, 'automaticAcceptance' => false, 'reviewStatus' => 'unreviewed', 'diagnostics' => ['editablePixels' => 1]];
        foreach ([['status' => 'completed'], ['status' => 'needs_attention'], ['status' => 'arbitrary'],
            ['status' => 'needs_attention', 'errorCode' => 'clipping_fraction_limit', 'candidate' => ['path' => 'unexpected']]] as $bad) {
            $this->executableReport($base + $bad);
            try { $service->run('/nonsecret/fixture.json', $snapshot); $this->fail('Invalid report was accepted'); }
            catch (RuntimeException $error) { $this->assertStringContainsString('bounded execution checks', $error->getMessage()); }
        }
        $this->executableReport($base + ['status' => 'needs_attention', 'errorCode' => 'memory_failure'], 1);
        $this->expectException(RuntimeException::class); $service->run('/nonsecret/fixture.json', $snapshot);
    }

    public function test_valid_rejection_retains_diagnostics_without_advertising_candidate(): void
    {
        $service = app(LabHarmonization::class); $snapshot = $service->snapshot(str_repeat('a', 64));
        $report = ['recipe' => $snapshot, 'automaticAcceptance' => false, 'reviewStatus' => 'unreviewed', 'status' => 'needs_attention',
            'diagnostics' => ['clippedPixelFraction' => 0.06], 'errorCode' => 'clipping_fraction_limit'];
        $this->executableReport($report); $this->assertSame($report, $service->run('/nonsecret/fixture.json', $snapshot));
        $dto = $service->dto($snapshot, $report, 'fixture-composition');
        $this->assertNull($dto['harmonizedCandidateSha256']); $this->assertNull($dto['clippingMaskAssetPath']);
        $this->assertSame(0.06, $dto['diagnostics']['clippedPixelFraction']);
        $this->assertNull($service->dto(null, null, 'legacy'));
    }

    private function executableReport(array $report, int $exit = 0): void
    {
        // Test-owned static output only. The production process never invokes a shell.
        file_put_contents($this->python, "#!/bin/sh\ncat <<'REPORT'\n".json_encode($report)."\nREPORT\nexit ".$exit."\n");
    }
}
