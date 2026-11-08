<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessProviderAutoDelivery implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 4;
    public array $backoff = [30, 120, 300];
    public int $timeout = 180;
    public int $uniqueFor = 300;
    public function uniqueId(): string { return 'provider-auto-delivery:'.$this->shootId; }
    public function __construct(public int $shootId) { $this->onQueue('default'); }
    public function handle(\App\Services\Shoots\ProviderAutoDeliveryService $provider): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('provider-auto-delivery:running:'.$this->shootId, 210);
        if (! $lock->get()) { $this->release(30); return; }
        try { $provider->run($this->shootId); } finally { $lock->release(); }
    }
}
