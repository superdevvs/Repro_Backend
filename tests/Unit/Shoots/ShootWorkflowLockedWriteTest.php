<?php

namespace Tests\Unit\Shoots;

use Tests\TestCase;

class ShootWorkflowLockedWriteTest extends TestCase
{
    public function test_workflow_writes_retry_sqlite_lock_contention(): void
    {
        $source = file_get_contents(app_path('Services/ShootWorkflowService.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('LockedWrite::run(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s+DB::transaction\(/m',
            $source,
            'Shoot workflow writes must go through LockedWrite, not a bare DB::transaction.'
        );
    }
}
