<?php

namespace App\Http\Resources\Messaging;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsThreadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userId = optional($request->user())->id;
        $unreadFor = $this->unread_for_user_ids_json ?? [];
        $isUnread = $userId ? in_array($userId, $unreadFor) : false;

        return [
            'id' => (string) $this->id,
            'contact' => SmsContactResource::make($this->whenLoaded('contact')),
            'lastMessageSnippet' => $this->last_snippet,
            'lastMessageAt' => optional($this->last_message_at)->toIso8601String(),
            'lastDirection' => $this->last_direction,
            'unread' => $isUnread,
            'status' => $this->status,
            'tags' => $this->tags_json ?? [],
            'assignedToUserId' => $this->assigned_to_user_id,
            'assignedTo' => $this->assignedTo ? [
                'id' => (string) $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ] : null,
        ];
    }
}

