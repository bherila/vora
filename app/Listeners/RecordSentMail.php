<?php

namespace App\Listeners;

use App\Services\Mail\MailDeliveryLog;
use Illuminate\Mail\Events\MessageSent;

class RecordSentMail
{
    public function __construct(private readonly MailDeliveryLog $log) {}

    public function handle(MessageSent $event): void
    {
        $this->log->record($event->sent, $event->data);
    }
}
