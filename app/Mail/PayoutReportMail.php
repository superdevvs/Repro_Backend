<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PayoutReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public array $summary;
    public Carbon $rangeStart;
    public Carbon $rangeEnd;
    public string $audience;

    /**
     * Create a new message instance.
     */
    public function __construct(string $recipientName, array $summary, Carbon $rangeStart, Carbon $rangeEnd, string $audience = 'photographer')
    {
        $this->recipientName = $recipientName;
        $this->summary = $summary;
        $this->rangeStart = $rangeStart;
        $this->rangeEnd = $rangeEnd;
        $this->audience = $audience;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $subject = sprintf(
            'Weekly payout recap (%s - %s)',
            $this->rangeStart->format('M d'),
            $this->rangeEnd->format('M d')
        );

        return $this->subject($subject)
            ->markdown('emails.payout-report', [
                'recipientName' => $this->recipientName,
                'summary' => $this->summary,
                'rangeStart' => $this->rangeStart,
                'rangeEnd' => $this->rangeEnd,
                'audience' => $this->audience,
            ]);
    }
}

