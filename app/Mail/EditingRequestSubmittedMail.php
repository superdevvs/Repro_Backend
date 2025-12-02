<?php

namespace App\Mail;

use App\Models\EditingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EditingRequestSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public EditingRequest $requestRecord;

    public function __construct(EditingRequest $requestRecord)
    {
        $this->requestRecord = $requestRecord;
    }

    public function build(): self
    {
        return $this->subject('New special editing request: ' . $this->requestRecord->tracking_code)
            ->markdown('emails.editing-request', [
                'request' => $this->requestRecord,
            ]);
    }
}

