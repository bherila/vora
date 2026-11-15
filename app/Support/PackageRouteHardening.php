<?php

namespace App\Support;

use App\Http\Middleware\RequireConfirmedPassword;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Adds application middleware to credential routes the bherila/auth-laravel
 * package registers without it: password confirmation before a new passkey is
 * enrolled.
 *
 * Applied once the application has booted, when the package's routes exist.
 * These belong in the package itself; this keeps the app safe until it ships
 * them, and adding the same middleware twice is harmless.
 */
final class PackageRouteHardening
{
    /** @return array<string, list<string>> "METHOD uri" => middleware */
    public static function middleware(): array
    {
        $prefix = trim((string) config('bherila-auth.routes.prefix', 'api'), '/');
        $uri = fn (string $path): string => ltrim($prefix.'/'.$path, '/');

        return [
            'POST '.$uri('passkeys/register/options') => [RequireConfirmedPassword::class],
            'POST '.$uri('passkeys/register') => [RequireConfirmedPassword::class],
        ];
    }

    public static function apply(Router $router): void
    {
        $wanted = self::middleware();

        /** @var Route $route */
        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $extra = $wanted[$method.' '.$route->uri()] ?? null;
                if ($extra === null) {
                    continue;
                }

                $missing = array_values(array_diff($extra, $route->middleware()));
                if ($missing !== []) {
                    $route->middleware($missing);
                }
            }
        }
    }
}
