<?php

namespace App\Mail;

use App\Models\WaitlistRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WaitlistRequest $waitlistRequest,
        public string $token,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your invitation request');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.waitlist.verify',
            with: [
                'verifyUrl' => route('waitlist.verify', [
                    'uuid' => $this->waitlistRequest->uuid,
                    'token' => $this->token,
                ]),
                'code' => $this->code,
            ],
        );
    }
}
