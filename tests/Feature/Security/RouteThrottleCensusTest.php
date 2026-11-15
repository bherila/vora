<?php

namespace Tests\Feature\Security;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Route throttle census: every route's resolved middleware, checked for the two
 * throttle mistakes a cross-repository security sweep kept finding.
 *
 * 1. **A numeric throttle without a prefix** (`throttle:5,1`, `throttle`,
 *    `ThrottleRequests::with(5, 1)`). ThrottleRequests keys such a limit on the
 *    user id (or domain|IP for guests) and nothing else, so every route using an
 *    unprefixed numeric throttle draws on one shared bucket per user: a burst on
 *    one route spends another route's budget, and the tightest limit wins
 *    everywhere. A named limiter (`throttle:name`) or a third, prefix argument
 *    (`throttle:5,1,name`) gives the route its own bucket.
 * 2. **A state-changing route with no throttle at all.** Any route accepting a
 *    method other than GET/HEAD/OPTIONS, or a GET whose URI or name says it exports
 *    data, must carry a throttle, or appear in UNTHROTTLED_ALLOWLIST with a reason.
 *
 * It also fails on `throttle:name` for a limiter nobody registered: ThrottleRequests
 * then treats the name as a max-attempts count and throws on every request.
 *
 * Middleware is read from Router::gatherRouteMiddleware(), which expands groups and
 * aliases and drops withoutMiddleware() exclusions, plus the kernel's global
 * middleware. So a throttle applied through a group counts. Here the web group
 * carries `throttle:web-writes` (bootstrap/app.php, App\Support\RateLimits), a
 * generous per-account backstop on every write; routes that send mail, notify
 * others or are expensive still need a tighter limiter of their own.
 *
 * The census runs on the booted application, including the shared auth
 * package routes and their named rate limits.
 *
 * The census sees only middleware. It cannot tell which routes send mail, call an
 * AI service, fan out notifications or export data under another name: those need
 * a dedicated named limiter, chosen in review (see "Security invariants" in
 * AGENTS.md).
 *
 * Ported from x-template's census; UNTHROTTLED_ALLOWLIST is this repository's.
 */
final class RouteThrottleCensusTest extends TestCase
{
    /**
     * Routes that may change state without any throttle, keyed by route name or,
     * for an unnamed route, by "METHODS uri" exactly as the failure message prints
     * it. Every entry needs a reason a reviewer can check; an entry that matches no
     * route fails the census so the list cannot rot.
     *
     * @var array<string, string>
     */
    private const UNTHROTTLED_ALLOWLIST = [
        'storage.local.upload' => 'Registered by the framework (FilesystemServiceProvider) because the local disk sets '
            .'serve => true in config/filesystems.php, and it cannot take route middleware. ReceiveFile aborts with '
            .'403/404 unless the request carries a signature the app issued through temporaryUploadUrl(), so only '
            .'a holder of an unexpired signed URL can write, and only to the signed path; vora issues none (it never '
            .'calls temporaryUploadUrl() on a local disk). SECURITY.md records that '
            .'the body size is not bounded by the app. Set serve => false if the app never issues local signed URLs.',
        'bherila-auth.delegated-access' => 'Registered by bherila/auth-laravel outside the web group (it is a '
            .'server-to-server endpoint for the identity provider, not a browser one). DelegatedAccessController '
            .'limits it itself, per IP, with RateLimiter::tooManyAttempts() (bherila-auth.delegated_access.per_minute, '
            .'default 120) before reading the body, and answers 404 without counting while the feature is disabled. '
            .'Every request must carry a signed, single-use actor assertion.',
    ];

    /**
     * Safe-method routes that must still be throttled because they are expensive
     * to serve: exports walk a whole data set per request.
     */
    private const EXPENSIVE_READ_PATTERN = '/export/i';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function test_no_route_uses_a_numeric_throttle_without_a_prefix(): void
    {
        $census = $this->census();

        self::assertSame([], $census['unprefixed'], $this->fixMessage(
            'These routes use a numeric throttle with no prefix, so they share one rate-limit bucket per user '
            .'with every other route doing the same:',
            $census['unprefixed'],
            "Fix: give each its own bucket. Prefer a named limiter registered in AppServiceProvider::boot():\n"
            ."    RateLimiter::for('invoices-send', fn (Request \$request) => Limit::perMinute(5)\n"
            ."        ->by(\$request->user()?->getAuthIdentifier() ?: \$request->ip()));\n"
            ."and use ->middleware('throttle:invoices-send'). Or add a unique third argument:\n"
            ."    throttle:5,1,invoices-send    or    ThrottleRequests::with(5, 1, 'invoices-send')",
        ));
    }

    public function test_named_throttles_refer_to_registered_limiters(): void
    {
        $census = $this->census();

        self::assertSame([], $census['unregistered'], $this->fixMessage(
            'These routes name a rate limiter that is not registered. ThrottleRequests then reads the name as a '
            .'max-attempts count and throws MissingRateLimiterException on every request:',
            $census['unregistered'],
            "Fix: register it in AppServiceProvider::boot() with RateLimiter::for('<name>', ...), or correct the name.",
        ));
    }

    public function test_every_state_changing_route_is_throttled(): void
    {
        $census = $this->census();

        self::assertSame([], $census['unthrottled'], $this->fixMessage(
            'These state-changing or export routes have no throttle:',
            $census['unthrottled'],
            "Fix: add ->middleware('throttle:<limiter>') with a named limiter sized for the action. Routes that\n"
            ."send mail, call an AI/LLM service, fan out notifications or export data need a tight limiter of\n"
            ."their own, not a shared default. If a route genuinely must stay unthrottled (for example a webhook\n"
            .'with signature verification whose sender retries), add it to UNTHROTTLED_ALLOWLIST in '
            .self::class.' with the reason.',
        ));
    }

    public function test_allowlist_entries_match_a_live_route(): void
    {
        $labels = [];
        foreach ($this->routes() as $route) {
            $labels[] = $this->routeLabel($route);
            if ($route->getName() !== null) {
                $labels[] = $route->getName();
            }
        }

        $stale = array_values(array_diff(array_keys(self::UNTHROTTLED_ALLOWLIST), $labels));

        self::assertSame([], $stale, 'UNTHROTTLED_ALLOWLIST in '.self::class.' names routes that no longer exist. '
            .'Remove these entries: '.implode(', ', $stale));
    }

    /**
     * The census must catch what it claims to catch, including routes a group
     * throttle does not reach; prove it on synthetic routes outside the web group.
     */
    public function test_census_detects_each_violation_class(): void
    {
        RateLimiter::for('census-named', fn (): Limit => Limit::perMinute(5));

        Route::post('__census/bare', fn (): string => 'ok')->middleware('throttle:5,1');
        Route::post('__census/bare-default', fn (): string => 'ok')->middleware('throttle');
        Route::post('__census/bare-guest-user', fn (): string => 'ok')->middleware('throttle:10|60,1');
        Route::post('__census/bare-with', fn (): string => 'ok')->middleware(ThrottleRequests::with(5, 1));
        Route::get('__census/bare-get', fn (): string => 'ok')->middleware('throttle:60,1');
        Route::post('__census/prefixed', fn (): string => 'ok')->middleware('throttle:5,1,census-prefixed');
        Route::post('__census/named', fn (): string => 'ok')->middleware('throttle:census-named');
        Route::middleware('throttle:census-named')->group(function (): void {
            Route::delete('__census/grouped', fn (): string => 'ok');
        });
        Route::post('__census/missing-limiter', fn (): string => 'ok')->middleware('throttle:census-unregistered');
        Route::post('__census/none', fn (): string => 'ok');
        Route::match(['GET', 'POST'], '__census/match', fn (): string => 'ok');
        Route::get('__census/report/export', fn (): string => 'ok');
        Route::get('__census/plain-read', fn (): string => 'ok');
        Route::post('__census/excluded', fn (): string => 'ok')
            ->middleware('throttle:census-named')
            ->withoutMiddleware('throttle:census-named');

        $census = $this->census();

        self::assertSame([
            'POST __census/bare',
            'POST __census/bare-default',
            'POST __census/bare-guest-user',
            'POST __census/bare-with',
            'GET|HEAD __census/bare-get',
        ], $this->synthetic($census['unprefixed']));
        self::assertSame(['POST __census/missing-limiter'], $this->synthetic($census['unregistered']));
        self::assertSame([
            'POST __census/none',
            'GET|POST|HEAD __census/match',
            'GET|HEAD __census/report/export',
            'POST __census/excluded',
        ], $this->synthetic($census['unthrottled']));
    }

    /**
     * @return array{unprefixed: list<string>, unregistered: list<string>, unthrottled: list<string>}
     */
    private function census(): array
    {
        $global = $this->globalMiddleware();
        $router = $this->app->make(Router::class);
        $census = ['unprefixed' => [], 'unregistered' => [], 'unthrottled' => []];

        foreach ($this->routes() as $route) {
            $label = $this->routeLabel($route).($route->getName() !== null ? '  (name: '.$route->getName().')' : '');
            $throttles = $this->throttlesIn([...$global, ...$router->gatherRouteMiddleware($route)]);

            foreach ($throttles as $params) {
                if (count($params) === 1 && ! is_numeric($params[0]) && ! str_contains($params[0], '|')) {
                    if (RateLimiter::limiter($params[0]) === null) {
                        $census['unregistered'][] = $label.'  [throttle:'.$params[0].']';
                    }

                    continue;
                }

                if (trim($params[2] ?? '') === '') {
                    $census['unprefixed'][] = $label.'  [throttle:'.implode(',', $params).']';
                }
            }

            if ($throttles === [] && $this->mustBeThrottled($route) && ! $this->isAllowlisted($route)) {
                $census['unthrottled'][] = $label;
            }
        }

        return $census;
    }

    /**
     * @return list<RoutingRoute>
     */
    private function routes(): array
    {
        return array_values(Route::getRoutes()->getRoutes());
    }

    /**
     * @return list<string|Closure>
     */
    private function globalMiddleware(): array
    {
        $kernel = $this->app->make(HttpKernel::class);

        return $kernel instanceof FoundationHttpKernel ? array_values($kernel->getGlobalMiddleware()) : [];
    }

    /**
     * The parameter lists of every throttle in a resolved middleware stack.
     *
     * @param  array<int, mixed>  $middleware
     * @return list<list<string>>
     */
    private function throttlesIn(array $middleware): array
    {
        $throttles = [];
        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            [$class, $parameters] = array_pad(explode(':', $entry, 2), 2, '');
            if ($class !== 'throttle' && ! (class_exists($class) && is_a($class, ThrottleRequests::class, true))) {
                continue;
            }

            $throttles[] = $parameters === '' ? [] : explode(',', $parameters);
        }

        return $throttles;
    }

    private function mustBeThrottled(RoutingRoute $route): bool
    {
        if (array_diff($route->methods(), self::SAFE_METHODS) !== []) {
            return true;
        }

        return preg_match(self::EXPENSIVE_READ_PATTERN, $route->uri().' '.($route->getName() ?? '')) === 1;
    }

    private function isAllowlisted(RoutingRoute $route): bool
    {
        return array_key_exists($this->routeLabel($route), self::UNTHROTTLED_ALLOWLIST)
            || ($route->getName() !== null && array_key_exists($route->getName(), self::UNTHROTTLED_ALLOWLIST));
    }

    private function routeLabel(RoutingRoute $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }

    /**
     * @param  list<string>  $violations
     */
    private function fixMessage(string $problem, array $violations, string $fix): string
    {
        return $problem."\n  - ".implode("\n  - ", $violations)."\n\n".$fix."\n";
    }

    /**
     * The synthetic routes registered by the self-test, without the name or throttle notes.
     *
     * @param  list<string>  $violations
     * @return list<string>
     */
    private function synthetic(array $violations): array
    {
        return array_values(array_map(
            fn (string $violation): string => explode('  ', $violation)[0],
            array_filter($violations, fn (string $violation): bool => str_contains($violation, '__census/')),
        ));
    }
}
