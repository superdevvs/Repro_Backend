<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class AccountingPayoutDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Carbon $rangeStart;
    public Carbon $rangeEnd;
    public Collection $photographerSummaries;
    public Collection $repSummaries;

    public function __construct(Carbon $rangeStart, Carbon $rangeEnd, Collection $photographerSummaries, Collection $repSummaries)
    {
        $this->rangeStart = $rangeStart;
        $this->rangeEnd = $rangeEnd;
        $this->photographerSummaries = $photographerSummaries;
        $this->repSummaries = $repSummaries;
    }

    public function build(): self
    {
        $subject = sprintf(
            'Payout approvals summary (%s - %s)',
            $this->rangeStart->format('M d'),
            $this->rangeEnd->format('M d')
        );

        return $this->subject($subject)
            ->markdown('emails.payout-digest', [
                'rangeStart' => $this->rangeStart,
                'rangeEnd' => $this->rangeEnd,
                'photographers' => $this->photographerSummaries,
                'reps' => $this->repSummaries,
                'totalPhotographerPayout' => $this->photographerSummaries->sum('gross_total'),
                'totalRepPayout' => $this->repSummaries->sum('commission_total'),
            ]);
    }
}

