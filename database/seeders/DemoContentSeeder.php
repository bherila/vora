<?php

namespace Database\Seeders;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Models\Character;
use App\Models\Favorite;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Notifications\ContentFavorited;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Populates a local/staging database with realistic, interconnected content so
 * the redesigned workflows (Feed, Explore filters + Save, profile-as-container
 * with character tabs and count badges, favorites, notifications, the media
 * viewer) can actually be clicked through — there is otherwise no data to see.
 *
 * Opt-in only: `php artisan db:seed --class=DemoContentSeeder`. It refuses to
 * run in production and is never wired into the default DatabaseSeeder, so it
 * cannot touch real data.
 *
 * Note: media object keys point at storage that does not exist locally, so
 * thumbnails/players render broken — the layout, tabs, counts, privacy badges,
 * filters, favorites and empty/processing states are what this makes testable.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoContentSeeder refuses to run in production.');

            return;
        }

        DB::transaction(function (): void {
            $interests = $this->seedInterests();
            $users = $this->seedUsers();
            $this->seedFollowGraph($users);
            $this->seedInterestRatings($users, $interests);
            $this->seedContent($users, $interests);
            $this->seedFavorites($users);
        });

        $this->command?->info('Demo content seeded. Log in with any of:');
        foreach (['ada', 'ben', 'cleo', 'dax', 'eve'] as $handle) {
            $this->command?->info("  {$handle}@demo.test / password");
        }
    }

    /**
     * A tiny two-level interest tree so the hierarchical picker and the
     * "default to my interests" Explore filter have something to show.
     *
     * @return array<string, Interest>
     */
    private function seedInterests(): array
    {
        $tree = [
            'Art' => ['Digital Art', 'Traditional Art'],
            'Outdoors' => ['Hiking', 'Camping'],
        ];

        $interests = [];
        foreach ($tree as $parentName => $children) {
            $parent = Interest::query()->firstOrCreate(['name' => $parentName]);
            $interests[$parentName] = $parent;
            foreach ($children as $childName) {
                $interests[$childName] = Interest::query()->firstOrCreate(
                    ['name' => $childName],
                    ['parent_interest_id' => $parent->id],
                );
            }
        }

        return $interests;
    }

    /**
     * @return array<string, User>
     */
    private function seedUsers(): array
    {
        $handles = [
            'ada' => 'Ada Lovelace',
            'ben' => 'Ben Carter',
            'cleo' => 'Cleo Nguyen',
            'dax' => 'Dax Romero',
            'eve' => 'Eve Park',
        ];

        $users = [];
        foreach ($handles as $handle => $name) {
            $users[$handle] = User::factory()->approved()->create([
                'name' => $name,
                'display_name' => $name,
                'email' => "{$handle}@demo.test",
            ]);
        }

        return $users;
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedFollowGraph(array $users): void
    {
        // Ada <-> Ben mutual; Cleo -> Ada accepted; Dax -> Ada pending; Eve alone.
        $this->follow($users['ada'], $users['ben'], 'accepted');
        $this->follow($users['ben'], $users['ada'], 'accepted');
        $this->follow($users['cleo'], $users['ada'], 'accepted');
        $this->follow($users['dax'], $users['ada'], 'pending');
    }

    private function follow(User $requester, User $recipient, string $status): void
    {
        FollowRequest::query()->firstOrCreate(
            ['requester_id' => $requester->id, 'recipient_id' => $recipient->id],
            ['status' => $status, 'responded_at' => $status === 'accepted' ? now() : null],
        );
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Interest>  $interests
     */
    private function seedInterestRatings(array $users, array $interests): void
    {
        // Ada and Ben share Digital Art (a mutual interest); each has a second.
        $this->rate($users['ada'], $interests['Digital Art'], 8);
        $this->rate($users['ada'], $interests['Hiking'], 5);
        $this->rate($users['ben'], $interests['Digital Art'], 6);
        $this->rate($users['ben'], $interests['Camping'], 7);
        $this->rate($users['cleo'], $interests['Traditional Art'], 9);
    }

    private function rate(User $user, Interest $interest, int $level): void
    {
        InterestRating::query()->updateOrCreate(
            ['user_id' => $user->id, 'character_id' => null, 'interest_id' => $interest->id],
            ['level' => $level],
        );
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Interest>  $interests
     */
    private function seedContent(array $users, array $interests): void
    {
        $ada = $users['ada'];
        $ben = $users['ben'];

        // Ada: a public + a followers-only persona, plus main-identity media.
        $persona = Character::factory()->for($ada)->create(['display_name' => 'Aria (persona)']);
        $secret = Character::factory()->for($ada)->audience(Audience::Followers)->create(['display_name' => 'Nyx (followers-only)']);

        // Main-identity gallery: a public photo, a followers-only photo, a
        // specific-people photo (Ben only), and a still-processing video.
        $public = $this->galleryPhoto($ada, null, Audience::Everyone, 'Sunrise over the bay');
        $public->interests()->syncWithoutDetaching([$interests['Digital Art']->id]);
        $this->galleryPhoto($ada, null, Audience::Followers, 'Studio work in progress');
        $specific = $this->galleryPhoto($ada, null, Audience::SpecificPeople, 'Just for Ben');
        $specific->syncAudienceMembers([$ben->id]);
        $this->processingVideo($ada, null, 'Timelapse (transcoding)');

        // Persona gallery (public) and the followers-only persona's media.
        $this->galleryPhoto($ada, $persona->id, Audience::Everyone, 'Aria reference sheet');
        $this->galleryPhoto($ada, $secret->id, Audience::Followers, 'Nyx concept');

        // Ben: a couple of public photos so Explore and his profile have content.
        $benPhoto = $this->galleryPhoto($ben, null, Audience::Everyone, 'Trailhead');
        $benPhoto->interests()->syncWithoutDetaching([$interests['Camping']->id]);
        $this->galleryPhoto($ben, null, Audience::Everyone, 'Campfire');

        // Stories: a published+approved read for others, plus a draft (owner-only).
        $adaStory = Story::factory()->for($ada)->published()->approved()->create(['title' => 'How I learned to paint light']);
        $adaStory->interests()->syncWithoutDetaching([$interests['Digital Art']->id]);
        Story::factory()->for($ada)->create(['title' => 'Unfinished draft', 'status' => 'draft']);
        Story::factory()->for($ben)->published()->approved()->create(['title' => 'A weekend on the ridge']);

        // Posts (approved so they show in feeds and the profile Posts tab).
        Post::factory()->for($ada)->approved()->create(['body' => 'New piece up in my gallery — feedback welcome!']);
        Post::factory()->for($ben)->approved()->create(['body' => 'Best trail mix recipe, fight me.']);
    }

    private function galleryPhoto(User $user, ?int $characterId, Audience $audience, string $title): Media
    {
        return Media::factory()->for($user)->approved()->create([
            'character_id' => $characterId,
            'audience' => $audience,
            'discoverable' => $audience === Audience::Everyone,
            'title' => $title,
            'purpose' => MediaPurpose::Gallery,
        ]);
    }

    private function processingVideo(User $user, ?int $characterId, string $title): Media
    {
        // No hls_content_id, so the player shows the "still processing" notice.
        return Media::factory()->for($user)->video()->approved()->create([
            'character_id' => $characterId,
            'audience' => Audience::Everyone,
            'title' => $title,
        ]);
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedFavorites(array $users): void
    {
        $ada = $users['ada'];
        $ben = $users['ben'];
        $cleo = $users['cleo'];

        // Ben saves one of Ada's public photos (and is notified-equivalent below).
        $adaPhoto = Media::query()->where('user_id', $ada->id)->whereNull('character_id')
            ->where('audience', Audience::Everyone->value)->first();
        if ($adaPhoto !== null) {
            $this->favorite($ben, $adaPhoto);
            // Surface one in-app notification so the bell is non-empty.
            $ada->notify(new ContentFavorited($ben, 'media', (string) $adaPhoto->title, "/m/{$adaPhoto->ulid}"));
        }

        // Cleo saves Ada's profile; Ada saves Ben's published story.
        $this->favorite($cleo, $ada);
        $benStory = Story::query()->where('user_id', $ben->id)->where('status', 'published')->first();
        if ($benStory !== null) {
            $this->favorite($ada, $benStory);
        }
    }

    private function favorite(User $user, object $item): void
    {
        Favorite::query()->firstOrCreate([
            'user_id' => $user->id,
            'favoritable_type' => $item->getMorphClass(),
            'favoritable_id' => $item->getKey(),
        ]);
    }
}
