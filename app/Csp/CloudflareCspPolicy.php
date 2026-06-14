<?php

namespace App\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Policies\Policy;

class CloudflareCspPolicy extends Policy
{
    public function configure()
    {
        $mediaOrigins = $this->mediaOrigins();

        $this
            ->addDirective(Directive::DEFAULT_SRC, ["'self'"])
            ->addDirective(Directive::SCRIPT_SRC, [
                "'self'",
                'https://static.cloudflareinsights.com',
            ])
            ->addNonce(Directive::SCRIPT_SRC)
            ->addDirective(Directive::CONNECT_SRC, [
                "'self'",
                'https://static.cloudflareinsights.com',
                // Presigned PUT uploads and fetching HLS playlists/segments.
                ...$mediaOrigins,
            ])
            ->addDirective(Directive::IMG_SRC, [
                "'self'",
                'https://static.cloudflareinsights.com',
                // Signed photo URLs and HLS poster frames.
                ...$mediaOrigins,
            ])
            ->addDirective(Directive::MEDIA_SRC, [
                "'self'",
                // Signed video preview URLs and HLS segments.
                ...$mediaOrigins,
            ])
            ->addDirective(Directive::STYLE_SRC, ["'self'"])
            ->addDirective(Directive::OBJECT_SRC, ["'none'"])
            ->addDirective(Directive::BASE_URI, ["'self'"])
            ->addDirective(Directive::FRAME_ANCESTORS, ["'none'"])
            ->addDirective(Directive::FORM_ACTION, ["'self'"]);
    }

    /**
     * Collect the distinct scheme+host origins the browser must reach for media:
     * the R2 storage endpoints (uploads, photo views) and the HLS playback host.
     * Derived from config so no bucket names or endpoints are hard-coded here.
     *
     * @return list<string>
     */
    private function mediaOrigins(): array
    {
        $candidates = [
            config('filesystems.disks.s3.endpoint'),
            config('filesystems.disks.photos.endpoint'),
            config('filesystems.disks.hls.endpoint'),
            config('media.hls_base_url'),
        ];

        $origins = [];

        foreach ($candidates as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);

            if ($scheme === null || $host === null) {
                continue;
            }

            $origins[$scheme.'://'.$host] = true;
        }

        return array_keys($origins);
    }
}
