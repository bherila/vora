<?php

namespace App\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

class CloudflareCspPreset implements Preset
{
    public function configure(Policy $policy): void
    {
        $mediaOrigins = $this->mediaOrigins();

        $policy
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::SCRIPT, [
                Keyword::SELF,
                'https://static.cloudflareinsights.com',
            ])
            ->addNonce(Directive::SCRIPT)
            ->add(Directive::CONNECT, [
                Keyword::SELF,
                'https://static.cloudflareinsights.com',
                // Presigned PUT uploads and fetching HLS playlists/segments.
                ...$mediaOrigins,
            ])
            ->add(Directive::IMG, [
                Keyword::SELF,
                'https://static.cloudflareinsights.com',
                // Signed photo URLs and HLS poster frames.
                ...$mediaOrigins,
            ])
            ->add(Directive::MEDIA, [
                Keyword::SELF,
                // Signed video preview URLs and HLS segments.
                ...$mediaOrigins,
            ])
            ->add(Directive::STYLE, Keyword::SELF)
            ->add(Directive::OBJECT, Keyword::NONE)
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->add(Directive::FORM_ACTION, Keyword::SELF);
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
