<?php

namespace App\Services\Post;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
use App\Models\Interest;
use App\Models\Post;
use App\Models\User;
use App\Support\FollowGraph;
use App\Support\MuteGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class FeedQueryService
{
    /** @return Builder<Post> */
    public function build(User $viewer, string $scope, ?Interest $interest = null): Builder
    {
        $viewerId = $viewer->id;
        $scope = $scope === 'mixed' ? 'mixed' : 'following';

        $query = Post::query()
            ->where('posts.is_feed_hidden', false)
            ->when($interest !== null, fn (Builder $query) => $query->where('posts.context_interest_id', $interest->id))
            ->where(function (Builder $query) use ($viewerId, $scope): void {
                $query->where('posts.user_id', $viewerId)
                    ->orWhereExists(function (QueryBuilder $sub) use ($viewerId): void {
                        FollowGraph::constrainViewerFollowsOwner($sub, 'posts.user_id', $viewerId, 'posts.character_id');
                    });

                if ($scope === 'mixed') {
                    $query->orWhere(function (Builder $public): void {
                        $public->where('posts.audience', Audience::Everyone->value)
                            ->where('posts.discoverable', true);
                    });
                }
            })
            ->where(function (Builder $query) use ($viewerId): void {
                $query->where('posts.user_id', $viewerId)
                    ->orWhere('posts.moderation_status', ModerationStatus::Approved->value);
            })
            ->whereHas('user', fn (Builder $query) => $query->active()->whereNotNull('approved_at'))
            ->viewableBy($viewer)
            ->with(['user.profilePicture', 'character.profilePicture', 'contextInterest', 'attachments.attachable']);

        MuteGraph::excludeMutedIdentities($query, $viewerId, 'posts.user_id', 'posts.character_id');

        return $query;
    }
}
