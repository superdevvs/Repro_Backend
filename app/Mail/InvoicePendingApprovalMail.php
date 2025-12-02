<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoicePendingApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;
    public $admin;

    /**
     * Create a new message instance.
     */
    public function __construct(Invoice $invoice, User $admin)
    {
        $this->invoice = $invoice;
        $this->admin = $admin;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $photographer = $this->invoice->photographer;
        $period = "{$this->invoice->billing_period_start->format('M j')} - {$this->invoice->billing_period_end->format('M j, Y')}";

        return $this->subject("Invoice Requires Approval - {$photographer->name} - {$period}")
            ->view('emails.invoice_pending_approval')
            ->with([
                'invoice' => $this->invoice,
                'photographer' => $photographer,
                'admin' => $this->admin,
                'period' => $period,
            ]);
    }
}


