<?php

// Stand-in for WP2's App\Jobs\ProcessIguideLabTourSurvey (spec 5.15) while WP3 is built without WP2. It is declared
// only when the real class cannot be autoloaded, so after integration the real job is pushed instead. Tests only record
// the push (RecordingQueueFake); nothing ever runs handle().

namespace App\Jobs {
    if (! class_exists(ProcessIguideLabTourSurvey::class)) {
        class ProcessIguideLabTourSurvey implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            use \Illuminate\Foundation\Bus\Dispatchable, \Illuminate\Queue\InteractsWithQueue, \Illuminate\Bus\Queueable, \Illuminate\Queue\SerializesModels;

            public function __construct(public string $surveyId)
            {
                $this->onConnection(\App\Services\IguideLab\LabTourConfig::queueConnection());
                $this->onQueue(\App\Services\IguideLab\LabTourConfig::queue());
            }

            public function handle(): void
            {
                throw new \LogicException('The WP3 stand-in survey job never runs.');
            }
        }
    }
}
