<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Services\Branding\AppIcon;
use App\Support\InstanceFeatures;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Public install metadata: the web app manifest and this installation's icon. */
class AppIconController extends Controller
{
    public function icon(string $variant, AppIcon $icon): BinaryFileResponse
    {
        $path = $icon->path($variant);
        abort_if($path === null, 404);

        // Every upload changes the versioned URL, so a fetched icon never changes.
        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function manifest(AppIcon $icon): JsonResponse
    {
        $name = (string) config('app.name');
        $manifest = [
            'id' => '/',
            'name' => $name,
            'short_name' => $name,
            'start_url' => InstanceFeatures::homePath(),
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
        ];
        $icons = [];
        foreach (['icon-192' => '192x192', 'icon-512' => '512x512'] as $variant => $sizes) {
            $url = $icon->url($variant);
            if ($url !== null) {
                $icons[] = ['src' => $url, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'any'];
            }
        }
        if ($icons !== []) {
            $manifest['icons'] = $icons;
        }

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
