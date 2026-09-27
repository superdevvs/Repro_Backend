<?php

namespace App\Services\Messaging;

use App\Models\AutomationRun;
use App\Models\Message;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\FinalizeProgressTracker;
use Illuminate\Support\Collection;

/** Start payment follow-up only after a client notification was accepted. */
class ShootDeliveryNotificationRecorder
{
    public function acceptedMessages(array $ids): Collection
    {
        return Message::whereIn('id', $ids)->whereIn('channel', ['EMAIL', 'SMS'])
            ->where('direction', 'OUTBOUND')->where('provider', '!=', 'INTERNAL')
            ->whereIn('status', ['SENT', 'DELIVERED'])->get();
    }

    public function clientWasNotified(Collection $messages, ?User $client): bool
    {
        if (! $client) {
            return false;
        }
        $phone = $this->phoneDigits((string) ($client->phonenumber ?? $client->phone ?? ''));

        return $messages->contains(function (Message $message) use ($client, $phone): bool {
            if ($message->channel === 'EMAIL') {
                return $client->email && strtolower(trim($message->to_address)) === strtolower(trim($client->email));
            }

            return $message->channel === 'SMS' && $phone !== '' && $this->phoneDigits($message->to_address) === $phone;
        });
    }

    public function record(Shoot $shoot, Collection $messages): bool
    {
        if ($shoot->isInternalTestShoot() || ! $this->clientWasNotified($messages, $shoot->client)) {
            return false;
        }
        // The conditional write preserves an anchor from a concurrent replay.
        Shoot::whereKey($shoot->id)->whereNull('shoot_ready_notified_at')->update(['shoot_ready_notified_at' => now()]);
        app(AutomationService::class)->schedulePaymentReminders($shoot->refresh());

        return true;
    }

    public function completeDeferredRun(AutomationRun $run): void
    {
        if ($run->status !== 'completed' || $run->trigger_type !== 'SHOOT_COMPLETED'
            || ! ($run->context_json['delivery_notification_full_order'] ?? false)) {
            return;
        }
        $shoot = Shoot::find($run->related_shoot_id ?? $run->context_json['shoot_id'] ?? null);
        if (! $shoot || $shoot->isInternalTestShoot()) {
            return;
        }
        $ids = $run->steps->flatMap(fn ($step) => $step->output_json['message_ids'] ?? [])->unique()->all();
        $messages = $this->acceptedMessages($ids);
        $progress = app(FinalizeProgressTracker::class);
        if ($messages->isEmpty()) {
            $progress->stageSkipped($shoot->id, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, 'No delivery notification was sent by the saved workflow.');

            return;
        }
        $clientNotified = $this->record($shoot, $messages);
        $progress->stageCompleted($shoot->id, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL,
            $clientNotified ? 'Client notification accepted for delivery' : 'Notification sent to configured recipients');
    }

    private function phoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) === 10 ? '1'.$digits : $digits;
    }
}
