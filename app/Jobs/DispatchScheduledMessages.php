<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Messaging\MessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchScheduledMessages implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 30;

    public function handle(MessagingService $messaging): void
    {
        Message::query()
            ->where('channel', 'EMAIL')
            ->where('status', 'SCHEDULED')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->chunkById(50, function ($messages) use ($messaging) {
                foreach ($messages as $message) {
                    $messaging->dispatchScheduledMessage($message);
                }
            });
    }
}





