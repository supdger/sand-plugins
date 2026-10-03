"""Regression tests for actual-index producer rules and local hook preservation."""

from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

GATE = Path(__file__).with_name("check-staged-plugin-support.py")
INSTALLER = Path(__file__).with_name("install-pre-commit-checks.sh")


class StagedSupportTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="sand-support-index-")
        self.root = Path(self.temp.name)
        self.git("init", "-q")
        self.git("config", "user.email", "test@example.invalid")
        self.git("config", "user.name", "Fixture")
        self.pair(">=0.1.0")
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")

    def tearDown(self):
        self.temp.cleanup()

    def git(self, *arguments):
        return subprocess.run(["git", *arguments], cwd=self.root, check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

    def pair(self, support, peer=None, domain="sand-iam"):
        for relative, value in [
            (f"{domain}/info.ini", support),
            (f"{domain}/plugin/{domain}/info.ini", support if peer is None else peer),
        ]:
            target = self.root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('app = ' + domain + '\nversion = 1.0.0\n'
                              + (f'support = "{value}"\n' if value is not None else ""))

    def gate(self):
        return subprocess.run([sys.executable, str(GATE)], cwd=self.root,
                              text=True, capture_output=True)

    def test_open_minimum_accepts_changed_pair(self):
        self.pair(">=0.2.0")
        self.git("add", "sand-iam")
        self.assertEqual(0, self.gate().returncode)

    def test_closed_missing_noncanonical_and_mismatched_fail(self):
        for root, peer in [("0.1.x", None), (">=0.1.0 <=0.2.0", None),
                           (None, None), (">=00.1.0", None),
                           (">=0.1.0", ">=0.2.0")]:
            with self.subTest(root=root, peer=peer):
                self.pair(root, peer)
                self.git("add", "sand-iam")
                self.assertNotEqual(0, self.gate().returncode)

    def test_correct_index_ignores_wrong_worktree_and_unrelated_dirty(self):
        self.pair(">=0.2.0")
        self.git("add", "sand-iam")
        self.pair("0.1.x")
        self.pair("0.1.x", domain="sandworkflow")
        self.assertEqual(0, self.gate().returncode)

    def test_unrelated_plugin_is_outside_gate_scope(self):
        self.pair("6.x", domain="external-plugin")
        self.git("add", "external-plugin")
        self.assertEqual(0, self.gate().returncode)

    def test_wrong_index_cannot_be_hidden_by_correct_worktree(self):
        self.pair("0.1.x")
        self.git("add", "sand-iam")
        self.pair(">=0.1.0")
        self.assertNotEqual(0, self.gate().returncode)

    def test_missing_mirror_fails(self):
        self.pair(">=0.2.0")
        self.git("rm", "-f", "sand-iam/plugin/sand-iam/info.ini")
        self.git("add", "sand-iam/info.ini")
        self.assertNotEqual(0, self.gate().returncode)

    def test_root_and_peer_rename_fail_as_missing_pairs(self):
        for source, target in [
            ("sand-iam/info.ini", "sand-iam/renamed.ini"),
            ("sand-iam/plugin/sand-iam/info.ini", "sand-iam/plugin/sand-iam/renamed.ini"),
        ]:
            with self.subTest(source=source):
                self.git("mv", source, target)
                result = self.gate()
                self.assertNotEqual(0, result.returncode)
                self.assertIn("missing or ambiguous", result.stderr)
                self.git("reset", "--hard", "HEAD")

    def test_installed_hook_preserves_old_hook_and_blocks_bad_actual_commit(self):
        scripts = self.root / "scripts"
        scripts.mkdir()
        shutil.copy2(GATE, scripts / GATE.name)
        old_hook = self.root / ".git/hooks/pre-commit"
        old_hook.write_text("#!/bin/sh\nprintf invoked > hook-invoked\n")
        old_hook.chmod(0o755)
        subprocess.run(["bash", str(INSTALLER)], cwd=self.root, check=True, capture_output=True)
        subprocess.run(["bash", str(INSTALLER)], cwd=self.root, check=True, capture_output=True)
        self.pair("0.1.x")
        self.git("add", "sand-iam")
        result = subprocess.run(["git", "commit", "-qm", "must fail"], cwd=self.root,
                                text=True, capture_output=True)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("rule conflict", result.stderr)
        self.assertEqual("invoked", (self.root / "hook-invoked").read_text())
        self.assertTrue(old_hook.with_name("pre-commit.before-sand-checks").exists())

    def test_preserved_hook_failure_propagates(self):
        old_hook = self.root / ".git/hooks/pre-commit"
        old_hook.write_text("#!/bin/sh\nexit 23\n")
        old_hook.chmod(0o755)
        subprocess.run(["bash", str(INSTALLER)], cwd=self.root, check=True, capture_output=True)
        result = subprocess.run([str(old_hook)], cwd=self.root, capture_output=True)
        self.assertEqual(23, result.returncode)

    def test_commit_a_checks_the_git_prepared_index(self):
        scripts = self.root / "scripts"
        scripts.mkdir()
        shutil.copy2(GATE, scripts / GATE.name)
        subprocess.run(["bash", str(INSTALLER)], cwd=self.root, check=True, capture_output=True)
        self.pair("0.1.x")
        result = subprocess.run(["git", "commit", "-am", "must fail"], cwd=self.root,
                                text=True, capture_output=True)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("rule conflict", result.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
