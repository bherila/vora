<?php

namespace Tests\Feature\Interests;

use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoundedArrayInputTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_batch_of_ratings_is_checked_and_written_in_a_few_queries(): void
    {
        $user = User::factory()->approved()->create();
        $interests = collect(range(1, 40))->map(fn (int $n): Interest => Interest::query()->create(['name' => "Interest {$n}"]));
        InterestRating::query()->create(['user_id' => $user->id, 'interest_id' => $interests[0]->id, 'level' => 1]);
        InterestRating::query()->create(['user_id' => $user->id, 'interest_id' => $interests[1]->id, 'level' => 2]);

        $ratings = $interests->map(fn (Interest $interest, int $i): array => ['interest_id' => $interest->id, 'level' => $i === 1 ? null : 4])->all();

        $this->actingAs($user);
        DB::enableQueryLog();
        $this->postJson('/api/interests/ratings', ['ratings' => $ratings])->assertOk();
        $queries = count(DB::getQueryLog());

        $this->assertLessThan(25, $queries, 'Ratings should not cost one query per row.');
        $this->assertSame(39, InterestRating::query()->where('user_id', $user->id)->whereNull('character_id')->count());
        $this->assertSame(4, InterestRating::query()->where('interest_id', $interests[0]->id)->value('level'));
        $this->assertDatabaseMissing('interest_ratings', ['user_id' => $user->id, 'interest_id' => $interests[1]->id]);
    }

    #[Test]
    public function an_unknown_interest_is_reported_on_its_own_row(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Known']);

        $this->actingAs($user)->postJson('/api/interests/ratings', ['ratings' => [
            ['interest_id' => $interest->id, 'level' => 1],
            ['interest_id' => 999999, 'level' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('ratings.1.interest_id');

        $this->assertDatabaseCount('interest_ratings', 0);
    }

    #[Test]
    public function oversized_rating_batches_are_rejected(): void
    {
        $user = User::factory()->approved()->create();
        $ratings = array_map(fn (int $id): array => ['interest_id' => $id, 'level' => 1], range(1, 5000));

        $this->actingAs($user)->postJson('/api/interests/ratings', ['ratings' => $ratings])
            ->assertStatus(422)->assertJsonValidationErrors('ratings');
    }

    #[Test]
    public function audience_and_interest_lists_are_bounded(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->patchJson('/api/account', ['audience_user_ids' => range(1, 5000)])
            ->assertStatus(422)->assertJsonValidationErrors('audience_user_ids');

        $this->postJson('/api/stories', [
            'title' => 'Bounded', 'type' => 'long_form', 'body' => 'Text',
            'interest_ids' => range(1, 5000),
            'audience_user_ids' => range(1, 5000),
        ])->assertStatus(422)->assertJsonValidationErrors(['interest_ids', 'audience_user_ids']);
    }

    #[Test]
    public function an_unknown_audience_member_is_reported_on_its_own_element(): void
    {
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        $this->actingAs($user)->patchJson('/api/account', ['audience_user_ids' => [$other->id, 999999, $user->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audience_user_ids.1', 'audience_user_ids.2'])
            ->assertJsonMissingValidationErrors('audience_user_ids.0');
    }

    #[Test]
    public function story_graphs_are_bounded(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->cyoa()->create();

        $this->actingAs($owner)->putJson("/api/stories/{$story->id}/graph", [
            'nodes' => array_map(fn (int $n): array => ['key' => "n{$n}"], range(1, 600)),
            'choices' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('nodes');

        $this->putJson("/api/stories/{$story->id}/graph", [
            'nodes' => [['key' => 'start', 'is_start' => true, 'body' => str_repeat('a', 100001)]],
            'choices' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('nodes.0.body');
    }

    #[Test]
    public function involvement_preference_page_variable_and_push_key_lists_are_bounded(): void
    {
        $user = User::factory()->approved()->create();
        $story = Story::factory()->for($user)->create();
        $this->actingAs($user);

        $involvements = array_map(fn (int $id): array => ['type' => 'user', 'id' => $id], range(1, 201));
        $this->postJson('/api/stories', ['title' => 'Bounded', 'type' => 'long_form', 'body' => 'Text', 'involvements' => $involvements])
            ->assertStatus(422)->assertJsonValidationErrors('involvements');
        $this->patchJson("/api/stories/{$story->id}", ['involvements' => $involvements])
            ->assertStatus(422)->assertJsonValidationErrors('involvements');

        $this->patchJson('/api/account', [
            'preferred_user_types' => ['human', 'furry', 'other', 'human'],
            'preferred_genders' => ['male', 'female', 'other', 'male'],
        ])->assertStatus(422)->assertJsonValidationErrors(['preferred_user_types', 'preferred_genders']);

        $keys = ['p256dh' => 'x', 'auth' => 'y', ...array_fill_keys(array_map(fn (int $n): string => "k{$n}", range(1, 7)), 'z')];
        $this->postJson('/api/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => $keys])
            ->assertStatus(422)->assertJsonValidationErrors('keys');

        $admin = User::factory()->admin()->create();
        $variables = array_fill_keys(array_map(fn (int $n): string => "v{$n}", range(1, 101)), 'value');
        $this->actingAs($admin)->postJson('/api/admin/pages', [
            'slug' => 'bounded', 'title' => 'Bounded', 'body_markdown' => 'Text', 'variables' => $variables,
            'is_published' => false, 'show_in_footer' => false, 'sort_order' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('variables');
    }
}
