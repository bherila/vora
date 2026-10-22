<?php

namespace App\Services\Events;

use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EventAccess
{
    public function enabled(): bool
    {
        return (bool) config('instance.modules.events', false);
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function visibleTo(Builder $query, ?User $viewer): Builder
    {
        if (! $this->enabled() || ! $viewer instanceof User || $viewer->trashed() || ! $viewer->isActive() || ! $viewer->isApproved()) {
            return $query->whereRaw('1 = 0');
        }
        if ($viewer->isAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $visible) => $visible->where('visibility', EventVisibility::Everyone->value)
            ->orWhereHas('members', fn (Builder $members) => $members->where('users.id', $viewer->id)));
    }

    public function canView(?User $viewer, ?Event $event): bool
    {
        if (! $event instanceof Event || ! $this->enabled() || ! $viewer instanceof User
            || $viewer->trashed() || ! $viewer->isActive() || ! $viewer->isApproved()) {
            return false;
        }
        if ($viewer->isAdmin() || $event->visibility === EventVisibility::Everyone) {
            return true;
        }

        return $event->relationLoaded('members') ? $event->members->contains('id', $viewer->id)
            : $event->members()->where('users.id', $viewer->id)->exists();
    }
}
