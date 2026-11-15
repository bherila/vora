<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\RateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `web-writes` backstop on the web group: writes are counted per account
 * in a bucket of their own, and reads are never counted.
 */
class WebWritesBackstopTest extends TestCase
{
    use RefreshDatabase;

    /** Spend a bucket the way ThrottleRequests keys a named limiter. */
    private function exhaust(string $key): void
    {
        foreach (range(1, RateLimits::WEB_WRITES_PER_MINUTE) as $ignored) {
            RateLimiter::hit(md5('web-writes'.$key), 60);
        }
    }

    #[Test]
    public function an_account_past_the_write_budget_gets_429_on_writes_but_can_still_read(): void
    {
        $user = User::factory()->approved()->create();
        $this->exhaust('user:'.$user->getKey());

        $this->actingAs($user)->postJson('/api/notifications/read-all')->assertStatus(429);
        $this->actingAs($user)->getJson('/api/notifications/unread-count')->assertOk();
    }

    #[Test]
    public function each_account_has_its_own_write_budget(): void
    {
        $spent = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $this->exhaust('user:'.$spent->getKey());

        $this->actingAs($other)->postJson('/api/notifications/read-all')->assertSuccessful();
    }

    #[Test]
    public function a_write_under_the_budget_is_not_limited(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/notifications/read-all')->assertSuccessful();
    }
}
