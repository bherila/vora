<?php

namespace App\Http\Middleware;

use App\Support\InstanceFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(InstanceFeatures::enabled($module), 404);

        return $next($request);
    }
}
