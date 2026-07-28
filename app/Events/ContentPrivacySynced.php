<?php

namespace App\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a privacy item's allowlist has finished changing. Eloquent's
 * saved event runs before syncAudienceMembers(), so announcement propagation
 * needs this second, explicit boundary to copy the final allowlist.
 */
class ContentPrivacySynced
{
    use Dispatchable;

    public function __construct(public readonly Model $content) {}
}
