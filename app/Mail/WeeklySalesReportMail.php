<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WeeklySalesReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public $salesRep;
    public $reportData;

    /**
     * Create a new message instance.
     */
    public function __construct(User $salesRep, array $reportData)
    {
        $this->salesRep = $salesRep;
        $this->reportData = $reportData;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $period = $this->reportData['period'];
        $weekLabel = "Week {$period['week_number']}, {$period['year']}";

        return $this->subject("Weekly Sales Report - {$weekLabel}")
            ->view('emails.weekly_sales_report')
            ->with([
                'salesRep' => $this->salesRep,
                'report' => $this->reportData,
                'weekLabel' => $weekLabel,
            ]);
    }
}


