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

    public function test_composer_never_blocks_on_the_root_prompt(): void
    {
        $script = $this->setupScript();
        $allowRoot = strpos($script, 'export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1');
        $detachStdin = strpos($script, "\nexec </dev/null\n");
        $firstComposerCall = preg_match('/^(?!\s*#).*\bcomposer (?:--version|check-platform-reqs|install|"\$)/m', $script, $match, PREG_OFFSET_CAPTURE)
            ? $match[0][1]
            : null;

        self::assertIsInt($allowRoot);
        self::assertIsInt($detachStdin);
        self::assertIsInt($firstComposerCall);
        self::assertTrue($allowRoot < $firstComposerCall);
        self::assertTrue($detachStdin < $firstComposerCall);
    }

    public function test_one_script_serves_both_setup_and_maintenance(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileDoesNotExist($root.'/codex/maintenance.sh');
        self::assertStringNotContainsString('--optimize-autoloader', $this->setupScript());
        self::assertStringNotContainsString('maintenance.sh', (string) file_get_contents($root.'/codex/README.md'));
    }
}
