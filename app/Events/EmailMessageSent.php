<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmailMessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Message $message)
    {
    }

    public function broadcastOn(): array
    {
        $isInternal = $this->message->provider === 'INTERNAL' && !empty($this->message->related_shoot_id);
        $channels = $isInternal ? [] : [new PrivateChannel('email.inbox')];

        // Also broadcast to the recipient if they have an account
        if ($this->message->to_address) {
            $recipient = \App\Models\User::where('email', $this->message->to_address)->first();
            if ($recipient) {
                $channels[] = new PrivateChannel('email.user.' . $recipient->id);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'EmailMessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'subject' => $this->message->subject,
            'from_address' => $this->message->from_address,
            'to_address' => $this->message->to_address,
            'sender_display_name' => $this->message->sender_display_name,
            'sender_user_id' => $this->message->sender_user_id,
            'direction' => $this->message->direction,
            'provider' => $this->message->provider,
            'send_source' => $this->message->send_source,
            'status' => $this->message->status,
            'related_shoot_id' => $this->message->related_shoot_id,
            'related_account_id' => $this->message->related_account_id,
            'thread_id' => $this->message->thread_id,
            'created_at' => $this->message->created_at->toISOString(),
            'body_text' => substr($this->message->body_text ?? '', 0, 200),
        ];
    }
}
