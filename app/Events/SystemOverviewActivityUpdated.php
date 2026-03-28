<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemOverviewActivityUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $kind,
        public array $payload,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('system-overview.superadmin')];
    }

    public function broadcastAs(): string
    {
        return 'SystemOverviewActivity';
    }

    public function broadcastWith(): array
    {
        return [
            'kind' => $this->kind,
            'payload' => $this->payload,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
