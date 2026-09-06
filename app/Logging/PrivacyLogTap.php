<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class PrivacyLogTap
{
    public function __invoke(Logger $logger): void
    {
        if (app()->environment('production') && $logger->getLogger() instanceof \Monolog\Logger) {
            foreach ($logger->getLogger()->getProcessors() as $processor) {
                if ($processor instanceof PrivacyLogProcessor) return;
            }
            $logger->getLogger()->pushProcessor(new PrivacyLogProcessor());
        }
    }
}
