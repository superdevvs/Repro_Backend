<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CancellationFeeInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;
    public $client;

    /**
     * Create a new message instance.
     */
    public function __construct(Invoice $invoice, User $client)
    {
        $this->invoice = $invoice;
        $this->client = $client;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $shoot = $this->invoice->shoot;
        $address = $shoot?->address ?? 'Property';

        return $this->subject("Cancellation Fee Invoice - {$address}")
            ->view('emails.cancellation_fee_invoice')
            ->with([
                'invoice' => $this->invoice,
                'client' => $this->client,
                'shoot' => $shoot,
                'address' => $address,
            ]);
    }
}
