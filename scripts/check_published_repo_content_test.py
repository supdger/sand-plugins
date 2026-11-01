"""Regression tests for the published-repo content gate."""

from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

GATE = Path(__file__).with_name("check-published-repo-content.py")


class PublishedRepoContentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="sand-published-content-")
        self.root = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def put(self, *relative_paths):
        for rel in relative_paths:
            target = self.root / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("payload\n")

    def run_gate(self, plugin):
        return subprocess.run(
            [sys.executable, str(GATE), "--plugin", plugin, "--root", str(self.root)],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
        )

    def test_clean_payload_passes(self):
        self.put("README.md", "info.ini", "install.sql",
                 "plugin/sand-iam/app/service/Work.php",
                 "sandadmin-artd/src/views/plugin/sand-iam/index.vue")
        result = self.run_gate("sand-iam")
        self.assertEqual(result.returncode, 0, result.stdout)
        self.assertIn("[PASS]", result.stdout)

    def test_test_dirs_mocks_and_ci_fail(self):
        self.put("plugin/sand-iam/tests/a_test.php",
                 "portal/tests/portal-contract.test.mjs",
                 ".github/workflows/ci.yml",
                 "sandadmin-artd/src/views/plugin/sand-iam/getting-started/a.http-mock.ts",
                 "docs/development/plan.md")
        result = self.run_gate("sand-iam")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout.count("[FAIL]"), 5, result.stdout)

    def test_examples_and_pinned_vendor_are_allowed_for_sand_iam(self):
        self.put("examples/webman-app/standalone/tests/offline_test.php",
                 "plugin/sand-iam/vendor/casbin/casbin/phpunit.xml",
                 "plugin/sand-iam/vendor/casbin/casbin/.github/workflows/phpunit.yml",
                 "sdk/typescript/dist/index.js")
        result = self.run_gate("sand-iam")
        self.assertEqual(result.returncode, 0, result.stdout)

    def test_vendor_tests_fail_where_no_exemption_exists(self):
        self.put("server/tests/SandPackage/FreshInstallTest.php",
                 "server/plugin/sandpackage/tests/x_test.php")
        result = self.run_gate("sand-package")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout.count("[FAIL]"), 2, result.stdout)

    def test_tooling_test_files_fail(self):
        self.put("tools/test-independent-release.php",
                 "server/scripts/build-plugin-package.php")
        result = self.run_gate("sand-package")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout.count("[FAIL]"), 1, result.stdout)
        self.assertIn("test-independent-release.php", result.stdout)

    def test_names_containing_test_are_not_flagged(self):
        self.put("server/plugin/sandpackage/app/service/latest-release.php",
                 "plugin/sand-iam/app/model/Contest.php")
        result = self.run_gate("sand-iam")
        self.assertEqual(result.returncode, 0, result.stdout)

    def test_env_examples_pass_but_env_fails(self):
        self.put("sandadmin-artd/.env.example",
                 "sandadmin-artd/.env.production.example",
                 "sandadmin-artd/.env.local")
        result = self.run_gate("sand-core")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout.count("[FAIL]"), 1, result.stdout)
        self.assertIn(".env.local", result.stdout)

    def test_undeclared_dist_fails(self):
        self.put("sandadmin-artd/dist/app.js")
        result = self.run_gate("sand-core")
        self.assertEqual(result.returncode, 1, result.stdout)


if __name__ == "__main__":
    unittest.main()
