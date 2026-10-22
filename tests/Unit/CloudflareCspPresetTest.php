<?php

namespace Tests\Unit;

use App\Csp\CloudflareCspPreset;
use Spatie\Csp\Policy;
use Tests\TestCase;

class CloudflareCspPresetTest extends TestCase
{
    public function test_media_endpoints_preserve_their_explicit_ports_for_uploads_and_playback(): void
    {
        config([
            'csp.nonce_enabled' => false,
            'filesystems.disks.s3.endpoint' => 'http://127.0.0.1:19000',
            'filesystems.disks.photos.endpoint' => 'http://127.0.0.1:19000/photos',
            'filesystems.disks.hls.endpoint' => 'https://media.example.test:8443/hls',
        ]);
        $policy = new Policy;
        (new CloudflareCspPreset)->configure($policy);

        foreach (explode(';', $policy->getContents()) as $directive) {
            if (! preg_match('/^(connect-src|img-src|media-src) /', $directive)) {
                continue;
            }
            $this->assertStringContainsString('http://127.0.0.1:19000', $directive);
            $this->assertStringContainsString('https://media.example.test:8443', $directive);
            $this->assertSame(1, substr_count($directive, 'http://127.0.0.1:19000'));
            $this->assertStringNotContainsString('/photos', $directive);
            $this->assertStringNotContainsString('/hls', $directive);
        }
    }

    public function test_media_endpoints_without_ports_keep_the_existing_https_origin(): void
    {
        config([
            'csp.nonce_enabled' => false,
            'filesystems.disks.s3.endpoint' => 'https://media.example.test',
            'filesystems.disks.photos.endpoint' => null,
            'filesystems.disks.hls.endpoint' => '',
        ]);
        $policy = new Policy;
        (new CloudflareCspPreset)->configure($policy);

        $this->assertStringContainsString('https://media.example.test', $policy->getContents());
        $this->assertStringNotContainsString('https://media.example.test:', $policy->getContents());
        $this->assertStringContainsString("object-src 'none'", $policy->getContents());
    }
}
