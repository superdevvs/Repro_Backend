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
            'aiPausedUntil' => optional($this->ai_paused_until)->toIso8601String(),
            'aiSessionId' => $this->ai_session_id,
            'aiRateLimitedAt' => $this->metadata['ai_rate_limited_at'] ?? null,
            'contactAiEnabled' => optional($this->contact)->sms_ai_enabled ?? null,
            'contactOptedOut' => optional($this->contact)->sms_opt_out ?? null,
            'group' => $this->sms_group_id && $this->smsGroup ? [
                'id' => $this->smsGroup->id,
                'name' => $this->smsGroup->name,
                'memberCount' => $this->smsGroup->members_count ?? $this->smsGroup->members->count(),
                'members' => $this->smsGroup->relationLoaded('members')
                    ? $this->smsGroup->members->map(fn ($member) => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'phone' => $member->phone,
                    ])->values()->all()
                    : [],
            ] : null,
        ];
    }
}

