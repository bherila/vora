<?php

namespace App\Console\Commands;

use App\Services\Branding\AppIcon;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class AppIconCommand extends Command
{
    protected $signature = 'ops:app-icon {path? : Square PNG, JPEG or WebP image} {--remove : Remove the installation icon}';

    protected $description = "Set or remove this installation's Home Screen and browser icon";

    public function handle(AppIcon $icon): int
    {
        if ($this->option('remove')) {
            $icon->remove();
            $this->info('App icon removed.');

            return self::SUCCESS;
        }
        $path = $this->argument('path');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            $this->error('Give the path of a readable image file, or --remove.');

            return self::FAILURE;
        }
        try {
            $icon->store((string) file_get_contents($path));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('App icon set: '.$icon->url('icon-512'));

        return self::SUCCESS;
    }
}
