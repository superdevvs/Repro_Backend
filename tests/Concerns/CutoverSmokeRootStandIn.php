<?php

namespace Tests\Concerns;

use App\Support\Cutover\CutoverEngineProbe;

/**
 * cutover:smoke and cutover:smoke-fixtures refuse to run as root. PHPUnit often
 * runs as root in containers, so there these tests stand in an unprivileged
 * effective user through the commands' one seam
 * (CutoverEngineProbe::EFFECTIVE_UID_SEAM, a container binding that lives as
 * long as the test's application). Run as any other user the commands read the
 * real effective user. The refusal itself has its own tests, which bind 0.
 */
trait CutoverSmokeRootStandIn
{
    private const NON_ROOT_STAND_IN = 65534;

    private function standInForRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->app->instance(CutoverEngineProbe::EFFECTIVE_UID_SEAM, self::NON_ROOT_STAND_IN);
        }
    }
}
