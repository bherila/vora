<?php

namespace App\Services\Branding;

use App\Services\SettingsService;
use GdImage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * The installation's own Home Screen and browser icon. An admin uploads one
 * square image; it is stored on this server (never in the repository) and
 * resized once into the variants browsers ask for. The version setting changes
 * with every upload so icon URLs can be cached forever.
 */
class AppIcon
{
    /** Variant name => edge length in pixels. */
    public const VARIANTS = [
        'icon-512' => 512,
        'icon-192' => 192,
        'apple-touch-icon' => 180,
        'favicon' => 32,
    ];

    public const VERSION_SETTING = 'app_icon_version';

    public const SHORT_NAME_SETTING = 'app_short_name';

    /** Longer labels are allowed but get cut off under Home Screen icons. */
    public const SHORT_NAME_MAX = 30;

    private const DIRECTORY = 'branding/app-icon';

    public function __construct(private readonly SettingsService $settings) {}

    public function version(): ?string
    {
        $version = $this->settings->get(self::VERSION_SETTING, '');

        return $version === null || $version === '' ? null : $version;
    }

    /** The label under the Home Screen icon, when it differs from APP_NAME. */
    public function shortName(): ?string
    {
        $name = $this->settings->get(self::SHORT_NAME_SETTING, '');

        return $name === null || $name === '' ? null : $name;
    }

    public function homeScreenName(): string
    {
        return $this->shortName() ?? (string) config('app.name');
    }

    public function setShortName(?string $name): void
    {
        $name = $name === null ? '' : trim($name);
        if (mb_strlen($name) > self::SHORT_NAME_MAX) {
            throw new InvalidArgumentException('The Home Screen name must be at most '.self::SHORT_NAME_MAX.' characters.');
        }
        $this->settings->set(self::SHORT_NAME_SETTING, $name === '' ? null : $name);
    }

    /** Root-relative, versioned URL for a variant, or null when no icon is set. */
    public function url(string $variant): ?string
    {
        $version = $this->version();
        if ($version === null || ! array_key_exists($variant, self::VARIANTS)) {
            return null;
        }

        return route('app-icon', ['variant' => $variant, 'v' => $version], false);
    }

    public function path(string $variant): ?string
    {
        if ($this->version() === null || ! array_key_exists($variant, self::VARIANTS)) {
            return null;
        }
        $file = self::DIRECTORY.'/'.$variant.'.png';

        return Storage::disk('local')->exists($file) ? Storage::disk('local')->path($file) : null;
    }

    /**
     * Replace the icon from raw image bytes (PNG, JPEG or WebP). Non-square
     * images are centre-cropped.
     */
    public function store(string $bytes): void
    {
        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw new InvalidArgumentException('The icon must be a PNG, JPEG or WebP image.');
        }
        $edge = min(imagesx($source), imagesy($source));
        if ($edge < self::VARIANTS['icon-192']) {
            throw new InvalidArgumentException('The icon must be at least 192 pixels square.');
        }
        $x = intdiv(imagesx($source) - $edge, 2);
        $y = intdiv(imagesy($source) - $edge, 2);

        $disk = Storage::disk('local');
        foreach (self::VARIANTS as $variant => $size) {
            // iOS draws transparent Home Screen icons on black, so the Apple
            // variant is flattened onto white; the others keep transparency.
            $image = $this->canvas($size, opaque: $variant === 'apple-touch-icon');
            imagecopyresampled($image, $source, 0, 0, $x, $y, $size, $size, $edge, $edge);
            ob_start();
            imagepng($image, null, 9);
            $png = (string) ob_get_clean();
            if (! $disk->put(self::DIRECTORY.'/'.$variant.'.png', $png)) {
                throw new RuntimeException('The icon could not be saved.');
            }
        }
        $this->settings->set(self::VERSION_SETTING, substr(hash('sha256', $bytes), 0, 16));
    }

    public function remove(): void
    {
        $this->settings->set(self::VERSION_SETTING, null);
        Storage::disk('local')->deleteDirectory(self::DIRECTORY);
    }

    private function canvas(int $size, bool $opaque): GdImage
    {
        $image = imagecreatetruecolor($size, $size);
        if ($opaque) {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        } else {
            imagealphablending($image, false);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagesavealpha($image, true);
        }

        return $image;
    }
}
