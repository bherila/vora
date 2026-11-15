<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the requested new address; the account switches only once it is clicked. */
class ConfirmEmailChangeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $confirmUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your new email address');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account.confirm-email-change', with: ['confirmUrl' => $this->confirmUrl]);
    }
}
