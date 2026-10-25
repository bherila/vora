<?php

namespace Tests\Feature\Mail;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ReplyToAddressTest extends TestCase
{
    public function test_configured_reply_to_is_added_to_every_message(): void
    {
        config([
            'mail.from' => ['address' => 'noreply@example.test', 'name' => 'Example Site'],
            'mail.reply_to' => ['address' => 'hello@example.test', 'name' => 'Example Hosts'],
        ]);

        $reply = $this->sendAndCapture()->getReplyTo();

        $this->assertCount(1, $reply);
        $this->assertSame('hello@example.test', $reply[0]->getAddress());
        $this->assertSame('Example Hosts', $reply[0]->getName());
    }

    public function test_unset_reply_to_leaves_replies_going_to_the_sender(): void
    {
        config(['mail.reply_to' => ['address' => null, 'name' => null]]);

        $this->assertSame([], $this->sendAndCapture()->getReplyTo());
    }

    public function test_reply_to_is_read_from_the_environment(): void
    {
        $_ENV['MAIL_REPLY_TO_ADDRESS'] = $_SERVER['MAIL_REPLY_TO_ADDRESS'] = 'hello@example.test';
        $_ENV['MAIL_REPLY_TO_NAME'] = $_SERVER['MAIL_REPLY_TO_NAME'] = 'Example Hosts';

        try {
            $config = require config_path('mail.php');
        } finally {
            unset($_ENV['MAIL_REPLY_TO_ADDRESS'], $_SERVER['MAIL_REPLY_TO_ADDRESS'], $_ENV['MAIL_REPLY_TO_NAME'], $_SERVER['MAIL_REPLY_TO_NAME']);
        }

        $this->assertSame(['address' => 'hello@example.test', 'name' => 'Example Hosts'], $config['reply_to'] ?? null);
    }

    private function sendAndCapture(): Email
    {
        Mail::mailer('array')->raw('Hello', fn (Message $message) => $message->to('guest@example.test')->subject('Hi'));

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last();

        return $sent->getOriginalMessage();
    }
}
