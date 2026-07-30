<?php

namespace App\Notifications\Concerns;

trait HasDatabaseActor
{
    /**
     * Add server-only actor identity used to enforce blocks on notification
     * queries. NotificationController removes these keys from client payloads,
     * so a Separate persona never exposes its owner account.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withDatabaseActor(
        array $payload,
        ?int $userId,
        ?int $characterId = null,
    ): array {
        return $payload + [
            '_actor_user_id' => $userId,
            '_actor_character_id' => $characterId,
        ];
    }
}
