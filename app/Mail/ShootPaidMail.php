<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class ShootPaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public object $shoot;
    public float $amount;

    public function __construct(User $user, object $shoot, float $amount)
    {
        $this->user = $user;
        $this->shoot = $shoot;
        $this->amount = $amount;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Shoot Has Been Marked as Paid',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.shoot_paid',
            with: [
                'user' => $this->user,
                'shoot' => $this->shoot,
                'amount' => $this->amount,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
