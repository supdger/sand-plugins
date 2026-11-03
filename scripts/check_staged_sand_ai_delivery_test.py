"""Regression tests for the SandAI delivery gate and its hook installation."""

from pathlib import Path
import json
import shutil
import subprocess
import sys
import tempfile
import unittest

GATE = Path(__file__).with_name("check-staged-sand-ai-delivery.py")
INSTALLER = Path(__file__).with_name("install-pre-commit-checks.sh")
MIRROR = "sand-ai/plugin/sand-ai"
RECORD = "sand-ai/.delivery-source.json"


class StagedSandAiDeliveryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="sand-ai-delivery-")
        self.root = Path(self.temp.name)
        self.git("init", "-q")
        self.git("config", "user.email", "test@example.invalid")
        self.git("config", "user.name", "Fixture")
        self.payload("original")
        self.record()
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")

    def tearDown(self):
        self.temp.cleanup()

    def git(self, *arguments):
        return subprocess.run(["git", *arguments], cwd=self.root, check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

    def write(self, relative, content):
        target = self.root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def payload(self, marker):
        self.write(f"{MIRROR}/info.ini", "app = sand-ai\n")
        self.write(f"{MIRROR}/app/admin/controller/Index.php", f"<?php // {marker}\n")

    def digest(self):
        result = subprocess.run([sys.executable, str(GATE), "--digest-tree", str(self.root / MIRROR)],
                                check=True, text=True, capture_output=True)
        content_sha256, count = result.stdout.split()
        return content_sha256, int(count)

    def record(self, content_sha256=None, file_count=None, **overrides):
        actual_sha256, actual_count = self.digest()
        payload = {
            "format": 1,
            "source_repo": "sand_ai",
            "source_commit": "0" * 40,
            "source_subdir": "server/plugin/sand-ai",
            "target_subdir": MIRROR,
            "generated_at_utc": "2026-01-01T00:00:00Z",
            "file_count": actual_count if file_count is None else file_count,
            "content_sha256": actual_sha256 if content_sha256 is None else content_sha256,
        }
        payload.update(overrides)
        self.write(RECORD, json.dumps(payload, indent=2) + "\n")

    def gate(self):
        return subprocess.run([sys.executable, str(GATE)], cwd=self.root,
                              text=True, capture_output=True)

    def test_delivered_payload_with_matching_record_passes(self):
        self.payload("delivered")
        self.record()
        self.git("add", "sand-ai")
        result = self.gate()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("[PASS]", result.stdout)

    def test_hand_edited_payload_without_record_update_fails(self):
        self.payload("hand edited")
        self.git("add", MIRROR)
        result = self.gate()
        self.assertNotEqual(0, result.returncode)
        self.assertIn("deliver-sand-ai.sh", result.stderr)

    def test_added_and_deleted_payload_files_fail(self):
        self.write(f"{MIRROR}/app/admin/controller/Extra.php", "<?php\n")
        self.git("add", MIRROR)
        self.assertNotEqual(0, self.gate().returncode)
        self.git("reset", "-q")
        self.git("rm", "-q", f"{MIRROR}/info.ini")
        self.assertNotEqual(0, self.gate().returncode)

    def test_forged_record_without_matching_tree_fails(self):
        self.record(content_sha256="f" * 64)
        self.git("add", RECORD)
        self.assertNotEqual(0, self.gate().returncode)

    def test_file_count_must_match_even_when_digest_is_copied(self):
        self.payload("delivered")
        self.record(file_count=99)
        self.git("add", "sand-ai")
        self.assertNotEqual(0, self.gate().returncode)

    def test_missing_or_malformed_record_fails(self):
        for case in ("missing", "format", "target"):
            with self.subTest(case=case):
                if case == "missing":
                    self.git("rm", "-q", RECORD)
                elif case == "format":
                    self.record(format=2)
                else:
                    self.record(target_subdir="sand-ai/app")
                self.payload("delivered")
                self.git("add", "sand-ai")
                self.assertNotEqual(0, self.gate().returncode)
                self.git("reset", "-q", "--mixed", "HEAD")
                subprocess.run(["git", "checkout", "-q", "--", "."], cwd=self.root, check=True)

    def test_non_delivered_path_under_payload_prefix_fails(self):
        self.write("sand-ai/plugin/strays/notes.md", "hand written\n")
        self.git("add", "sand-ai/plugin/strays")
        result = self.gate()
        self.assertNotEqual(0, result.returncode)
        self.assertIn("non-delivered", result.stderr)

    def test_index_is_authoritative_over_worktree(self):
        self.payload("delivered")
        self.record()
        self.git("add", "sand-ai")
        self.payload("dirty worktree edit")  # staged content stays consistent
        self.assertEqual(0, self.gate().returncode)
        self.git("add", MIRROR)
        self.assertNotEqual(0, self.gate().returncode)

    def test_unrelated_staged_change_is_outside_gate_scope(self):
        self.write("README.md", "unrelated\n")
        self.git("add", "README.md")
        result = self.gate()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("skipped", result.stdout)

    def test_installer_upgrade_keeps_preserved_hook_and_blocks_bad_commit(self):
        scripts = self.root / "scripts"
        scripts.mkdir()
        for gate in (GATE, GATE.with_name("check-staged-plugin-support.py")):
            shutil.copy2(gate, scripts / gate.name)
        nested = scripts / "check-nested-git.sh"
        nested.write_text("#!/bin/sh\nexit 0\n")
        nested.chmod(0o755)
        hook = self.root / ".git/hooks/pre-commit"
        hook.write_text("#!/bin/sh\nprintf invoked > hook-invoked\n")
        hook.chmod(0o755)
        subprocess.run(["bash", str(INSTALLER)], cwd=self.root, check=True, capture_output=True)
        # A stale generated hook must be upgraded in place, never re-preserved.
        hook.write_text(hook.read_text().replace("pre-commit-v2", "pre-commit-v1"))
        subprocess.run(["bash", str(INSTALLER)], cwd=self.root, check=True, capture_output=True)
        self.assertIn("pre-commit-v2", hook.read_text())
        self.assertEqual("#!/bin/sh\nprintf invoked > hook-invoked\n",
                         hook.with_name("pre-commit.before-sand-checks").read_text())
        self.payload("hand edited in the delivery repo")
        self.git("add", MIRROR)
        result = subprocess.run(["git", "commit", "-qm", "must fail"], cwd=self.root,
                                text=True, capture_output=True)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("deliver-sand-ai.sh", result.stderr)
        self.assertEqual("invoked", (self.root / "hook-invoked").read_text())


if __name__ == "__main__":
    unittest.main(verbosity=2)
