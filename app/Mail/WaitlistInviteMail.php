<?php

namespace App\Mail;

use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invite $invite) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to join");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.waitlist.invite',
            with: [
                'inviteUrl' => route('invite.landing', ['uuid' => $this->invite->uuid]),
            ],
        );
    }
}
