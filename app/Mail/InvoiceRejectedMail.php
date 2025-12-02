<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;

    /**
     * Create a new message instance.
     */
    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $photographer = $this->invoice->photographer;
        $period = "{$this->invoice->billing_period_start->format('M j')} - {$this->invoice->billing_period_end->format('M j, Y')}";

        return $this->subject("Invoice Rejected - {$period}")
            ->view('emails.invoice_rejected')
            ->with([
                'invoice' => $this->invoice,
                'photographer' => $photographer,
                'period' => $period,
            ]);
    }
}


