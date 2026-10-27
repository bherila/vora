<?php

namespace Tests\Feature\Branding;

use App\Models\User;
use App\Services\Branding\AppIcon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['app.name' => 'Example Gathering']);
    }

    /** A PNG with a transparent left half, so cropping and flattening are visible. */
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 30, 60));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function upload(string $bytes, string $name = 'icon.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_the_manifest_names_the_installation_and_lists_icons_only_once_uploaded(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Example Gathering')->assertJsonPath('display', 'standalone')
            ->assertJsonMissingPath('icons');
        $this->get('/app-icon/icon-512.png')->assertNotFound();
        $this->get('/privacy')->assertSee('rel="manifest"', false)->assertDontSee('apple-touch-icon');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/api/admin/app-icon', ['icon' => $this->upload($this->png(800, 600))], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.app_name', 'Example Gathering');

        $icon = app(AppIcon::class);
        $version = $icon->version();
        $this->assertNotNull($version);
        $manifest = $this->get('/manifest.webmanifest')->json();
        $this->assertSame([
            ['src' => "/app-icon/icon-192.png?v={$version}", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => "/app-icon/icon-512.png?v={$version}", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ], $manifest['icons']);
        $this->get('/privacy')->assertSee('href="/app-icon/apple-touch-icon.png?v='.$version.'"', false)
            ->assertSee('href="/app-icon/favicon.png?v='.$version.'"', false);

        foreach (AppIcon::VARIANTS as $variant => $size) {
            $response = $this->get("/app-icon/{$variant}.png?v={$version}")->assertOk()
                ->assertHeader('Content-Type', 'image/png');
            $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
            $this->assertSame([$size, $size], array_slice((array) getimagesize((string) $icon->path($variant)), 0, 2));
        }
    }

    public function test_variants_are_centre_cropped_and_only_the_apple_icon_is_flattened(): void
    {
        // 800x600: the centred square spans x 100..699, so its left edge is transparent.
        app(AppIcon::class)->store($this->png(800, 600));
        $icon = app(AppIcon::class);

        $any = imagecreatefrompng((string) $icon->path('icon-512'));
        $this->assertSame(127, imagecolorsforindex($any, imagecolorat($any, 5, 256))['alpha']);
        $this->assertSame(['red' => 200, 'green' => 30, 'blue' => 60, 'alpha' => 0], imagecolorsforindex($any, imagecolorat($any, 506, 256)));

        // iOS fills transparency with black, so the Apple icon sits on white.
        $apple = imagecreatefrompng((string) $icon->path('apple-touch-icon'));
        $this->assertSame(['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 0], imagecolorsforindex($apple, imagecolorat($apple, 5, 90)));
    }

    public function test_only_admins_change_the_icon_and_bad_images_are_rejected(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->postJson('/api/admin/app-icon', ['icon' => $this->upload($this->png(512, 512))])->assertForbidden();
        $this->deleteJson('/api/admin/app-icon')->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/admin/app-icon', ['icon' => $this->upload($this->png(120, 120))])
            ->assertUnprocessable()->assertJsonValidationErrorFor('icon');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"><rect width="512" height="512"/></svg>';
        $this->postJson('/api/admin/app-icon', ['icon' => $this->upload($svg, 'icon.svg')])
            ->assertUnprocessable()->assertJsonValidationErrorFor('icon');
        $this->assertNull(app(AppIcon::class)->version());
    }

    public function test_replacing_changes_the_version_and_removing_restores_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/admin/app-icon', ['icon' => $this->upload($this->png(512, 512))])->assertOk();
        $first = app(AppIcon::class)->version();
        $this->postJson('/api/admin/app-icon', ['icon' => $this->upload($this->png(640, 640))])->assertOk();
        $second = app(AppIcon::class)->version();
        $this->assertNotSame($first, $second);
        // The old URL must not serve the new image under an immutable cache.
        $this->get("/app-icon/icon-512.png?v={$first}")->assertRedirect("/app-icon/icon-512.png?v={$second}")
            ->assertHeader('Cache-Control', 'no-cache, private');
        $this->get('/app-icon/icon-512.png')->assertRedirect("/app-icon/icon-512.png?v={$second}");
        $this->get("/app-icon/icon-512.png?v={$second}")->assertOk();
        $this->getJson('/api/admin/app-icon')->assertJsonPath('data.icon_url', "/app-icon/icon-512.png?v={$second}");

        $this->deleteJson('/api/admin/app-icon')->assertOk()->assertJsonPath('data.icon_url', null);
        $this->get('/app-icon/icon-512.png')->assertNotFound();
        $this->get('/manifest.webmanifest')->assertJsonMissingPath('icons');
        Storage::disk('local')->assertMissing('branding/app-icon/icon-512.png');
    }

    public function test_operators_can_set_the_icon_from_the_command_line(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'icon');
        file_put_contents($path, $this->png(512, 512));

        $this->artisan('ops:app-icon', ['path' => $path])->assertSuccessful();
        $this->assertNotNull(app(AppIcon::class)->version());
        $this->artisan('ops:app-icon', ['path' => $path.'-missing'])->assertFailed();
        $this->artisan('ops:app-icon', ['--remove' => true])->assertSuccessful();
        $this->assertNull(app(AppIcon::class)->version());
        unlink($path);
    }
}
