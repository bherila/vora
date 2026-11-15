<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the current address so its owner learns of a change they did not make. */
class EmailChangeRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $newEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your email address is being changed');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account.email-change-requested', with: ['newEmail' => $this->newEmail]);
    }
}
