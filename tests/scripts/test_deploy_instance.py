"""Exercise release source and filesystem safety without SSH or a database."""
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/deploy-instance-remote.sh'
RELEASE_WORKFLOW = SCRIPT.parent.parent / '.github/workflows/release-instance.yml'


class ReleaseWorkflowSourceTest(unittest.TestCase):
    def source_guard(self, repository, source_ref):
        workflow = RELEASE_WORKFLOW.read_text()
        step = workflow.split('      - name: Validate release source\n', 1)[1].split('\n      - ', 1)[0]
        script = textwrap.dedent(step.split('        run: |\n', 1)[1])
        return subprocess.run(['bash', '-c', script], env={
            **os.environ, 'CALLER_REPOSITORY': repository, 'SOURCE_REF': source_ref,
        }, text=True, capture_output=True, check=False)

    def test_cross_repository_call_requires_an_explicit_vora_ref(self):
        result = self.source_guard('bherila/kath', '')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('must set source_ref', result.stdout)

    def test_cross_repository_call_accepts_an_explicit_vora_commit(self):
        self.assertEqual(self.source_guard('bherila/kath', 'a' * 40).returncode, 0)

    def test_primary_release_can_keep_the_triggering_commit_default(self):
        self.assertEqual(self.source_guard('bherila/vora', '').returncode, 0)

    def test_checkout_uses_vora_instead_of_the_callers_repository(self):
        workflow = RELEASE_WORKFLOW.read_text()
        checkout = workflow.split('      - uses: actions/checkout@v6\n', 1)[1].split('\n      - ', 1)[0]
        self.assertIn('repository: bherila/vora', checkout)
        self.assertIn('ref: ${{ inputs.source_ref || github.sha }}', checkout)


class InstanceDeploymentTest(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory(prefix='.vora-release-test-', dir=Path.home())
        self.root = Path(self.workspace.name)
        self.app = self.root / 'app'
        self.app.mkdir()
        (self.app / '.env').write_text('APP_PROFILE=event\n')
        self.log = self.root / 'commands.log'
        self.php = self.root / 'php'
        self.php.write_text('''#!/usr/bin/env bash
set -euo pipefail
printf '%s\\n' "$*" >> "$TEST_DEPLOY_COMMAND_LOG"
if [ "$2" = config:cache ]; then touch bootstrap/cache/config.php; fi
if [ "$2" = ops:instance-profile ] && [ "${TEST_DEPLOY_PROFILE_MATCH:-true}" = false ]; then exit 1; fi
''')
        self.php.chmod(0o700)
        self.target = self.root / 'web'

    def tearDown(self):
        self.workspace.cleanup()

    def deploy(self, roots=None, profile_matches=True, path=None):
        return subprocess.run([
            'bash', str(SCRIPT), str((path or self.app).relative_to(Path.home())), str(self.php),
            'event', str(self.target.relative_to(Path.home())) if roots is None else roots,
        ], env={**os.environ, 'TEST_DEPLOY_COMMAND_LOG': str(self.log),
                'TEST_DEPLOY_PROFILE_MATCH': str(profile_matches).lower()},
            text=True, capture_output=True, check=False)

    def test_release_preserves_state_and_creates_public_symlink(self):
        (self.app / 'storage').mkdir()
        retained = self.app / 'storage' / 'retained.bin'
        retained.write_bytes(b'retained')
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.target.readlink(), self.app / 'public')
        self.assertEqual(retained.read_bytes(), b'retained')
        self.assertEqual((self.app / '.env').read_text(), 'APP_PROFILE=event\n')
        commands = self.log.read_text().splitlines()
        self.assertLess(commands.index('artisan ops:instance-profile --expect=event'),
                        commands.index('artisan migrate --force --no-interaction'))
        self.assertEqual((self.app / 'bootstrap/cache/config.php').stat().st_mode & 0o777, 0o600)

    def test_existing_directory_is_never_removed_or_migrated(self):
        self.target.mkdir()
        marker = self.target / 'unrelated.txt'
        marker.write_text('keep')
        self.assertNotEqual(self.deploy().returncode, 0)
        self.assertEqual(marker.read_text(), 'keep')
        self.assertFalse(self.log.exists())

    def test_application_directory_cannot_become_a_web_symlink(self):
        self.assertNotEqual(self.deploy(str(self.app.relative_to(Path.home()))).returncode, 0)
        self.assertFalse(self.log.exists())
        self.assertTrue(self.app.is_dir())

    def test_symlink_ancestor_cannot_be_replaced_with_a_self_reference(self):
        alias = self.root / 'alias'
        alias.symlink_to(self.root, target_is_directory=True)
        result = self.deploy(roots=str(alias.relative_to(Path.home())), path=alias / 'app')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(alias.readlink(), self.root)
        self.assertFalse(self.log.exists())

    def test_document_root_with_a_domain_name_is_supported(self):
        self.target = self.root / 'event.example.com'
        self.assertEqual(self.deploy().returncode, 0)
        self.assertEqual(self.target.readlink(), self.app / 'public')

    def test_mismatched_profile_stops_before_database_changes(self):
        self.assertNotEqual(self.deploy(profile_matches=False).returncode, 0)
        self.assertNotIn('artisan migrate', self.log.read_text())
        self.assertFalse(self.target.exists())

    def test_direct_document_root_requires_no_symlink(self):
        self.assertEqual(self.deploy(roots='').returncode, 0)
        self.assertFalse(self.target.exists())


if __name__ == '__main__':
    unittest.main()
