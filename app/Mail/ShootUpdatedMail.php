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
    public bool $isPhotographer;

    public function __construct(User $user, object $shoot, ?string $changesSummary = null, bool $isPhotographer = false)
    {
        $this->user = $user;
        $this->shoot = $shoot;
        $this->changesSummary = $changesSummary;
        $this->isPhotographer = $isPhotographer;
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
                'isPhotographer' => $this->isPhotographer,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
