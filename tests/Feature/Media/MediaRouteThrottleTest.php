<?php

namespace Tests\Feature\Media;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Upload creation (which runs the owner's duplicate scan) and Event media
 * downloads (which stream the original) each have their own rate limit.
 */
class MediaRouteThrottleTest extends TestCase
{
    private function route(string $method, string $uri): RoutingRoute
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (RoutingRoute $route): bool => $route->uri() === $uri && in_array($method, $route->methods(), true));
        $this->assertInstanceOf(RoutingRoute::class, $route, "{$method} {$uri}");

        return $route;
    }

    #[Test]
    public function media_upload_and_event_download_are_throttled_in_their_own_buckets(): void
    {
        $this->assertContains('throttle:120,1,media-upload', $this->route('POST', 'api/media')->gatherMiddleware());
        $this->assertContains('throttle:120,1,event-media-download', $this->route('GET', 'api/events/media/{ulid}/download')->gatherMiddleware());
    }
}
