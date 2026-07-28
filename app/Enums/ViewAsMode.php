<?php

namespace App\Enums;

enum ViewAsMode: string
{
    case Public = 'public';
    case Follower = 'follower';

    public function audienceDescription(): string
    {
        return match ($this) {
            self::Public => "someone who doesn't follow you",
            self::Follower => 'someone who follows you',
        };
    }
}
