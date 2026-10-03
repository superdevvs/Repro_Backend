<?php

namespace App\Services\Messaging;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Shoot;
use App\Models\ShootActivityLog;

/** Event-specific facts for manual resends; never invent change/refund history. */
final class ManualShootNotificationContext
{
    public function resolve(Shoot $shoot, string $type): array
    {
        $context = [];
        if (in_array($type, ['shoot_updated', 'shoot_request_modified', 'assignment_change'], true)) {
            $logs = ShootActivityLog::where('shoot_id', $shoot->id)->where('action', 'shoot_updated')->latest('id')->limit(100)->get();
            $log = $type === 'assignment_change'
                ? $logs->first(fn ($row) => data_get($row->metadata, 'changes.photographer_id.from'))
                : $logs->first();
            $changes = (array) data_get($log?->metadata, 'changes', []);
            $labels = ['address' => 'Address', 'scheduled_date' => 'Date', 'time' => 'Time', 'timezone' => 'Timezone', 'services' => 'Services'];
            $lines = [];
            foreach ($labels as $field => $label) {
                $change = $changes[$field] ?? null;
                if (is_array($change) && array_key_exists('to', $change)) {
                    $value = is_array($change['to']) ? implode(', ', $change['to']) : (string) $change['to'];
                    $lines[] = $label.': '.$value;
                }
            }
            $context['shoot_changes'] = implode("\n", $lines);
            $assignment = $changes['photographer_id'] ?? [];
            if (($assignment['to'] ?? null) == $shoot->photographer_id && ! empty($assignment['from'])) {
                $context['previous_photographer_id'] = $assignment['from'];
                $context['new_photographer_id'] = $assignment['to'];
                $context['assignment_message'] = 'Your shoot assignment has changed. Please review the current shoot details.';
            }
        }
        if (in_array($type, ['payment_receipt', 'payment_thank_you'], true)) {
            $context['payment'] = $shoot->payments()->where('status', Payment::STATUS_COMPLETED)->latest('id')->first();
        }
        if ($type === 'payment_due_reminder') {
            $context['invoice'] = Invoice::query()->where('role', Invoice::ROLE_CLIENT)
                ->where('client_id', $shoot->client_id)
                ->where(fn ($query) => $query->where('shoot_id', $shoot->id)->orWhereHas('shoots', fn ($shoots) => $shoots->where('shoots.id', $shoot->id)))
                ->latest('id')->first();
        }
        if ($type === 'refund_submitted') {
            $refund = PaymentRefund::where('shoot_id', $shoot->id)->whereIn('status', ['succeeded', 'completed'])->latest('id')->first();
            if ($refund) {
                $context['payment'] = $refund->payment;
                $context['refund_amount'] = $refund->amount;
                $context['refund_reason'] = $refund->reason;
            }
        }

        return $context;
    }
}
