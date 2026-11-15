"""Exercise make release against disposable local Git repositories; never contact GitHub."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ReleaseCommandTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='swc-release-command-')
        self.addCleanup(self.temp.cleanup)
        self.work = Path(self.temp.name)
        self.repo = self.work / 'checkout'
        self.remote = self.work / 'origin.git'
        self.env = dict(os.environ, GIT_CONFIG_GLOBAL=os.devnull, GIT_CONFIG_NOSYSTEM='1',
                        GIT_AUTHOR_NAME='Release Test', GIT_AUTHOR_EMAIL='release@example.test',
                        GIT_COMMITTER_NAME='Release Test', GIT_COMMITTER_EMAIL='release@example.test')
        self.run_command(['git', 'init', '--bare', '-q', str(self.remote)], cwd=self.work)
        self.run_command(['git', 'init', '-q', '-b', 'main', str(self.repo)], cwd=self.work)
        (self.repo / 'bin').mkdir()
        shutil.copy(ROOT / 'bin/release.sh', self.repo / 'bin/release.sh')
        shutil.copy(ROOT / 'Makefile', self.repo / 'Makefile')
        self.main = self.repo / 'sw-cloudcalendar.php'
        self.main.write_text("<?php\n/**\n * Version:           1.0.0\n */\n"
                             "define( 'SW_CALENDAR_VERSION', '1.0.0' );\n")
        self.commit()
        self.git('remote', 'add', 'origin', str(self.remote))
        self.git('push', '-q', 'origin', 'main')
        self.initial = self.git('rev-parse', 'HEAD').stdout.strip()

    def run_command(self, args, cwd=None, success=True):
        result = subprocess.run(args, cwd=cwd or self.repo, env=self.env,
                                text=True, capture_output=True, timeout=30)
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def git(self, *args, success=True):
        return self.run_command(['git', *args], success=success)

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-q', '-m', 'Test fixture')

    def release(self, version=None, success=True):
        args = ['make', 'release']
        if version is not None:
            args.append('VERSION=' + version)
        return self.run_command(args, success=success)

    def assert_unchanged(self):
        self.assertEqual(self.initial, self.git('rev-parse', 'HEAD').stdout.strip())
        self.assertEqual('', self.git('tag', '--list').stdout)
        self.assertIn("'1.0.0'", self.main.read_text())

    def test_version_commit_and_annotated_tag_are_pushed_together(self):
        self.release('1.2.3')
        self.assertIn('Version:           1.2.3', self.main.read_text())
        self.assertIn("define( 'SW_CALENDAR_VERSION', '1.2.3' );", self.main.read_text())
        head = self.git('rev-parse', 'HEAD').stdout.strip()
        self.assertNotEqual(self.initial, head)
        self.assertEqual('Release 1.2.3', self.git('log', '-1', '--format=%s').stdout.strip())
        self.assertEqual('tag', self.git('cat-file', '-t', 'refs/tags/1.2.3').stdout.strip())
        refs = self.git('ls-remote', 'origin').stdout
        self.assertIn(head + '\trefs/heads/main', refs)
        self.assertIn(head + '\trefs/tags/1.2.3^{}', refs)
        self.assertEqual('', self.git('status', '--porcelain').stdout)

    def test_four_part_version(self):
        self.release('1.2.3.4')
        self.assertIn("'1.2.3.4'", self.main.read_text())

    def test_same_version_only_creates_tag(self):
        self.release('1.0.0')
        self.assertEqual(self.initial, self.git('rev-parse', 'HEAD').stdout.strip())
        self.assertIn('refs/tags/1.0.0', self.git('ls-remote', '--tags', 'origin').stdout)

    def test_invalid_and_missing_versions_do_not_change_checkout(self):
        for version in (None, '', 'v1.2.3', '1.2', '1.2.3-rc1', '1.2.3;touch injected'):
            with self.subTest(version=version):
                self.release(version, success=False)
                self.assert_unchanged()
                self.assertFalse((self.repo / 'injected').exists())

    def test_dirty_tracked_untracked_and_staged_changes_are_refused(self):
        for state in ('tracked', 'untracked', 'staged'):
            with self.subTest(state=state):
                path = self.main if state == 'tracked' else self.repo / 'notes.txt'
                original = path.read_text() if path.exists() else None
                path.write_text((original or '') + 'dirty\n')
                if state == 'staged':
                    self.git('add', 'notes.txt')
                self.release('1.2.3', success=False)
                self.assertEqual(self.initial, self.git('rev-parse', 'HEAD').stdout.strip())
                self.assertEqual('', self.git('tag', '--list').stdout)
                if state == 'staged':
                    self.git('restore', '--staged', 'notes.txt')
                if original is None:
                    path.unlink()
                else:
                    path.write_text(original)

    def test_existing_local_tag_is_refused(self):
        self.git('tag', '1.2.3')
        self.release('1.2.3', success=False)
        self.assertEqual(self.initial, self.git('rev-parse', 'HEAD').stdout.strip())

    def test_existing_remote_tag_is_refused(self):
        self.git('tag', '1.2.3')
        self.git('push', '-q', 'origin', 'refs/tags/1.2.3')
        self.git('tag', '-d', '1.2.3')
        self.release('1.2.3', success=False)
        self.assert_unchanged()

    def test_detached_head_is_refused(self):
        self.git('checkout', '-q', '--detach')
        self.release('1.2.3', success=False)
        self.assert_unchanged()

    def test_unreachable_remote_is_refused_before_modification(self):
        self.git('remote', 'set-url', 'origin', str(self.work / 'missing.git'))
        self.release('1.2.3', success=False)
        self.assert_unchanged()

    def test_downgrade_is_refused(self):
        self.release('0.9.0', success=False)
        self.assert_unchanged()

    def test_inconsistent_versions_are_refused(self):
        self.main.write_text(self.main.read_text().replace("'1.0.0'", "'2.0.0'"))
        self.commit()
        before = self.main.read_bytes()
        self.release('3.0.0', success=False)
        self.assertEqual(before, self.main.read_bytes())
        self.assertEqual('', self.git('tag', '--list').stdout)

    def test_rejected_push_changes_neither_remote_branch_nor_tag(self):
        hook = self.remote / 'hooks/pre-receive'
        hook.write_text('#!/bin/sh\nexit 1\n')
        hook.chmod(0o700)
        self.release('1.2.3', success=False)
        refs = self.git('ls-remote', 'origin').stdout
        self.assertIn(self.initial + '\trefs/heads/main', refs)
        self.assertNotIn('refs/tags/1.2.3', refs)
        self.assertIn('1.2.3', self.git('tag', '--list').stdout)


if __name__ == '__main__':
    unittest.main(verbosity=2)
