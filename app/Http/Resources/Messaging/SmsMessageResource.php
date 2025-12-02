<?php

namespace App\Http\Resources\Messaging;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'threadId' => (string) $this->thread_id,
            'direction' => $this->direction,
            'from' => $this->from_address,
            'to' => $this->to_address,
            'body' => $this->body_text,
            'status' => $this->status,
            'sentAt' => optional($this->created_at)->toIso8601String(),
            'providerMessageId' => $this->provider_message_id,
        ];
    }
}

