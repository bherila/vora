<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Support\MuteGraph;

trait SuppressesMutedActor
{
    protected function actorIsMuted(object $notifiable, int $ownerId, ?int $characterId = null): bool
    {
        return $notifiable instanceof User
            && MuteGraph::isMutedIdentity($notifiable->id, $ownerId, $characterId);
    }
}
