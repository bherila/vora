<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class InstanceProfileCommand extends Command
{
    protected $signature = 'ops:instance-profile {--expect= : Fail if the configured deployment profile differs}';

    protected $description = 'Report deployment capabilities without exposing credentials';

    public function handle(): int
    {
        $profile = config('instance.profile');
        if ($this->option('expect') !== null && $profile !== $this->option('expect')) {
            $this->error('Configured instance profile does not match the deployment target.');

            return self::FAILURE;
        }

        $this->line(json_encode([
            'profile' => $profile,
            'modules' => config('instance.modules'),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
