<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class ShootUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public object $shoot;
    public ?string $changesSummary;

    public function __construct(User $user, object $shoot, ?string $changesSummary = null)
    {
        $this->user = $user;
        $this->shoot = $shoot;
        $this->changesSummary = $changesSummary;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Scheduled Photo Shoot for ' . $this->shoot->location . ' Updated',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.shoot_updated',
            with: [
                'user' => $this->user,
                'shoot' => $this->shoot,
                'changesSummary' => $this->changesSummary,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
