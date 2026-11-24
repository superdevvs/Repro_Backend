<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** One email that left (or tried to leave) the CRM, with the exact content sent. */
class CrmEmailSend extends Model
{
    protected $table = 'crm_email_sends';

    protected $guarded = ['id'];

    public function acceptedByProvider(): bool
    {
        return $this->status === 'sent' && filled($this->provider_message_id);
    }

    public function requireProviderAcceptance(): void
    {
        if (! $this->acceptedByProvider()) {
            $message = match ($this->status) {
                'logged' => 'The email was saved as a preview. Live delivery is off.',
                'uncertain', 'queued' => 'Delivery is unconfirmed. Check the provider before sending again.',
                default => $this->error ?: 'The provider did not accept this email.',
            };
            throw new \App\Services\Crm\Email\CrmSendBlocked((string) $this->status, $message);
        }
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'first_opened_at' => 'datetime',
            'bounced_at' => 'datetime', 'complained_at' => 'datetime', 'replied_at' => 'datetime',
        ];
    }
}
