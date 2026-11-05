<?php

namespace App\Listeners;

use App\Services\Mail\MailDeliveryLog;
use BWH\AuthManager\Embedded\EmbeddedContext;
use Illuminate\Mail\Events\MessageSent;

class RecordSentMail
{
    public function __construct(private readonly MailDeliveryLog $log) {}

    public function handle(MessageSent $event): void
    {
        // Provider mail belongs to its own directory. Its context switches the
        // default connection, so host bookkeeping would query provider tables.
        if (app()->bound(EmbeddedContext::class) && app(EmbeddedContext::class)->active()) {
            return;
        }

        $this->log->record($event->sent, $event->data);
    }
}
