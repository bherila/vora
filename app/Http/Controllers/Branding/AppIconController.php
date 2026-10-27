<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Services\Branding\AppIcon;
use App\Support\InstanceFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Public install metadata: the web app manifest and this installation's icon. */
class AppIconController extends Controller
{
    public function icon(Request $request, string $variant, AppIcon $icon): BinaryFileResponse|RedirectResponse
    {
        $path = $icon->path($variant);
        abort_if($path === null, 404);
        // Only the current version may be cached forever; an older or missing
        // version (a stale manifest or page) is sent to the current URL.
        if ($request->query('v') !== $icon->version()) {
            return redirect((string) $icon->url($variant))->header('Cache-Control', 'no-cache');
        }

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
            'short_name' => $icon->homeScreenName(),
            'start_url' => InstanceFeatures::homePath(),
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
        ];
        if (InstanceFeatures::enabled('events')) {
            // Android lists the installed app in its share sheet. The service
            // worker receives this POST and hands the files to the gallery.
            $manifest['share_target'] = [
                'action' => '/events/share',
                'method' => 'POST',
                'enctype' => 'multipart/form-data',
                'params' => ['files' => [['name' => 'media', 'accept' => ['image/*', 'video/*']]]],
            ];
        }
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
