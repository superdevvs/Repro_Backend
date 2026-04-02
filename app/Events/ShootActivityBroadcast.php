<?php

namespace App\Events;

use App\Models\Shoot;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShootActivityBroadcast implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public Shoot $shoot;
    public string $activityType;
    public string $message;
    public array $metadata;
    public ?int $userId;

    /**
     * Activity types visible to clients (their own shoots only)
     */
    protected array $clientVisibleActions = [
        'shoot_requested',
        'shoot_created',
        'shoot_scheduled',
        'shoot_approved',
        'shoot_started',
        'shoot_completed',
        'shoot_cancelled',
        'shoot_put_on_hold',
        'hold_requested',
        'hold_approved',
        'hold_rejected',
        'shoot_submitted_for_review',
        'payment_done',
        'media_uploaded',
    ];

    /**
     * Activity types visible to photographers (their assigned shoots only)
     */
    protected array $photographerVisibleActions = [
        'shoot_created',
        'shoot_scheduled',
        'shoot_approved',
        'shoot_started',
        'shoot_completed',
        'shoot_cancelled',
        'shoot_put_on_hold',
        'hold_approved',
        'media_uploaded',
    ];

    /**
     * Activity types visible to editors (their assigned shoots only)
     */
    protected array $editorVisibleActions = [
        'shoot_editing_started',
        'shoot_submitted_for_review',
        'media_uploaded',
        'hold_approved',
    ];

    public function __construct(
        Shoot $shoot,
        string $activityType,
        string $message,
        array $metadata = [],
        ?int $userId = null
    ) {
        $this->shoot = $shoot->loadMissing(['client:id,name,company_name', 'photographer:id,name', 'editor:id,name']);
        $this->activityType = $activityType;
        $this->message = $message;
        $this->metadata = $metadata;
        $this->userId = $userId ?? auth()->id();
    }

    public function broadcastOn(): array
    {
        $channelNames = [
            'admin.notifications',
            'shoot.' . $this->shoot->id,
        ];

        // Client channel - if client exists and action is visible to clients
        if ($this->shoot->client_id && in_array($this->activityType, $this->clientVisibleActions)) {
            $channelNames[] = 'client.' . $this->shoot->client_id . '.notifications';
        }

        if (in_array($this->activityType, $this->photographerVisibleActions, true)) {
            if ($this->shoot->photographer_id) {
                $channelNames[] = 'photographer.' . $this->shoot->photographer_id . '.notifications';
            }

            foreach ($this->shoot->getUniqueServicePhotographers() as $photographer) {
                $channelNames[] = 'photographer.' . $photographer->id . '.notifications';
            }
        }

        // Editor channel - if editor assigned and action is visible to editors
        if ($this->shoot->editor_id && in_array($this->activityType, $this->editorVisibleActions)) {
            $channelNames[] = 'editor.' . $this->shoot->editor_id . '.notifications';
        }

        return collect($channelNames)
            ->unique()
            ->values()
            ->map(fn (string $channelName) => new PrivateChannel($channelName))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'ShootActivity';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => 'shoot-' . $this->shoot->id . '-' . time(),
            'shootId' => $this->shoot->id,
            'activityType' => $this->activityType,
            'message' => $this->message,
            'address' => $this->shoot->address,
            'clientName' => $this->shoot->client?->name,
            'photographerName' => $this->shoot->photographer?->name,
            'status' => $this->shoot->status,
            // Only include non-sensitive metadata
            'metadata' => $this->sanitizeMetadata($this->metadata),
            'userId' => $this->userId,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Remove sensitive data from metadata for broadcast
     */
    protected function sanitizeMetadata(array $metadata): array
    {
        // Remove sensitive keys that shouldn't be broadcast to all channels
        $sensitiveKeys = [
            'company_notes',
            'photographer_notes', 
            'editor_notes',
            'internal_notes',
        ];

        return array_diff_key($metadata, array_flip($sensitiveKeys));
    }
}
