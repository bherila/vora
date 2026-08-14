<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CodexSetupScriptTest extends TestCase
{
    private function setupScript(): string
    {
        $path = dirname(__DIR__, 2).'/codex/setup.sh';
        $contents = file_get_contents($path);

        self::assertIsString($contents);

        return $contents;
    }

    public function test_setup_script_has_valid_bash_syntax(): void
    {
        $process = new Process(['bash', '-n', dirname(__DIR__, 2).'/codex/setup.sh']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_composer_platform_validation_runs_before_pnpm(): void
    {
        $script = $this->setupScript();
        $platformCheck = strpos($script, 'composer check-platform-reqs --lock');
        $phpInstall = strpos($script, 'composer "${composer_args[@]}"');
        $phpStep = strpos($script, "  install_php_dependencies\n");
        $pnpmStep = strpos($script, "  ensure_pnpm\n");

        self::assertIsInt($platformCheck);
        self::assertIsInt($phpInstall);
        self::assertIsInt($phpStep);
        self::assertIsInt($pnpmStep);
        self::assertTrue($platformCheck < $phpInstall);
        self::assertTrue($phpStep < $pnpmStep);
    }

    public function test_setup_does_not_bypass_or_rebuild_the_php_platform(): void
    {
        $script = $this->setupScript();

        self::assertStringNotContainsString('phpenv install', $script);
        self::assertStringNotContainsString('--ignore-platform-req', $script);
        self::assertStringNotContainsString('install_node_dependencies &', $script);
    }
}
