<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
require_once __DIR__ . '/isolated_sandiam_fixture.php';
$authoritySnapshot = sandIamAuthorityPayloadSnapshot($root);
$tool = $root . '/tools/check-package-integrity.php';
$payloadPolicy = $root . '/tools/package-payload-policy.php';
$app = $root . '/plugin/sand-iam/config/app.php';
$process = $root . '/plugin/sand-iam/config/process.php';
$recoveryDescriptorContract = $root . '/plugin/sand-iam/tests/failed_upgrade_recovery_descriptor_non_pg_test.php';

/** @return array{0:int,1:string} */
$runToolAt = static function (string $fixtureRoot, array $arguments): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixtureRoot . '/tools/check-package-integrity.php');
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    return [$status, implode(PHP_EOL, $output)];
};
$runTool = static fn (array $arguments): array => $runToolAt($root, $arguments);

/** @return array<string, mixed>|null */
$candidateManifest = static function () use ($runTool): ?array {
    [$status, $output] = $runTool(['--print-candidate-manifest']);
    if ($status !== 0) {
        return null;
    }
    try {
        $manifest = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($manifest) ? $manifest : null;
};

$canonicalize = null;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map($canonicalize, $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = $canonicalize($item);
    }
    return $value;
};

/** @return array<string, mixed> */
$releaseManifest = static function (array $candidate, string $signature) : array {
    $candidate['schema'] = 'sand-iam.release-provenance/v1';
    $candidate['kind'] = 'external-reviewed-release';
    $candidate['provenance'] = [
        'source' => 'integrity-negative-test',
        'reference' => 'integrity-negative-test',
        'approved_by' => 'integrity-negative-test',
        'approved_at' => '2026-08-22T00:00:00Z',
        'signature' => ['algorithm' => 'ed25519', 'value' => $signature],
    ];
    return $candidate;
};

/** @param callable(string): bool $callback */
$withPrivateTempDirectory = static function (callable $callback): bool {
    $seed = tempnam('/private/tmp', 'sand-iam-integrity-');
    if ($seed === false || !unlink($seed) || !mkdir($seed, 0700)) {
        return false;
    }
    try {
        return $callback($seed);
    } finally {
        $entries = scandir($seed);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($seed . '/' . $entry);
                }
            }
        }
        rmdir($seed);
    }
};

/**
 * Replace a file only inside an isolated fixture. The authoritative package
 * path must never be passed here.
 *
 * @param callable(string): bool $callback
 */
$withTemporarilyReplacedFixtureFile = static function (string $path, string $replacement, callable $callback): bool {
    $original = file_get_contents($path);
    if (!is_string($original) || file_put_contents($path, $replacement) === false) {
        return false;
    }
    try {
        return $callback($original);
    } finally {
        file_put_contents($path, $original);
    }
};

$checks = [
    'package integrity tool exists' => is_file($tool),
    'tool verifies dynamic contiguous revisions and mirrored hashes' => static function () use ($tool): bool {
        $source = file_get_contents($tool);
        return is_string($source)
            && str_contains($source, 'migration revisions are contiguous and begin at 001')
            && str_contains($source, 'range(1, max($revisionNumbers))')
            && str_contains($source, 'lifecycle builder manifest declares every root migration')
            && str_contains($source, 'root and plugin migration payloads have matching names and hashes')
            && str_contains($source, 'root and plugin lifecycle payloads have matching hashes');
    },
    'tool checks composer, SAML runtime and protocol adapter' => static function () use ($tool): bool {
        $source = file_get_contents($tool);
        return is_string($source)
            && str_contains($source, 'composer declares complete platform and locked SAML runtime dependency')
            && str_contains($source, "'COMPOSER_ROOT_VERSION' => '0.7.3'")
            && str_contains($source, "\"'pretty_version' => '0.7.3'\"")
            && str_contains($source, 'SAML dependency resolves through composer autoload')
            && str_contains($source, 'protocol adapter guards and references the declared SAML runtime');
    },
    'tool checks shared package payload policy, SQL constraints and version/support metadata' => static function () use ($tool, $payloadPolicy, $root): bool {
        $source = file_get_contents($tool);
        $policy = file_get_contents($payloadPolicy);
        if (!is_string($source) || !is_string($policy)) {
            return false;
        }
        require_once $payloadPolicy;
        $payload = sandIamPayloadFiles($root, true);
        return str_contains($source, 'package-payload-policy.php')
            && in_array('plugin/sand-iam/vendor/autoload.php', $payload, true)
            && in_array('docs/user-guide/sand-iam-operator-guide.md', $payload, true)
            && in_array('plugin/sand-iam/bin/check-runtime-configuration.php', $payload, true)
            && in_array('plugin/sand-iam/bin/RuntimeConfigurationPreflight.php', $payload, true)
            && in_array('plugin/sand-iam/bin/check-runtime-requirements.php', $payload, true)
            && in_array('plugin/sand-iam/bin/RuntimeRequirementsPreflight.php', $payload, true)
            && in_array('docs/user-guide/configuration-reference.md', $payload, true)
            && in_array('release-build-contract.json', $payload, true)
            && !in_array('docs/development/sand-iam-task-board.md', $payload, true)
            && !in_array('plugin/sand-iam/tests/package_integrity_contract_non_pg_test.php', $payload, true)
            && !in_array('sdk/dart/test/client_test.dart', $payload, true)
            && !in_array('sdk/dart/test/management_client_test.dart', $payload, true)
            && !in_array('sandadmin-artd/src/views/plugin/sand-iam/getting-started/wizardState.contract.test.ts', $payload, true)
            && !in_array('sandadmin-artd/src/views/plugin/sand-iam/getting-started/getting-started.behavior.ts', $payload, true)
            && str_contains($source, 'admin UI payload')
            && str_contains($source, 'account portal runtime source and packaged assets')
            && str_contains($source, 'public/account/account.js')
            && str_contains($source, 'SDK and user-facing documentation')
            && str_contains($source, 'CycloneDX SBOM matches committed dependency locks')
            && str_contains($source, 'PostgreSQL SQL has no sa_* business table or MySQL dialect')
            && str_contains($source, 'release metadata versions and host support are consistent')
            && str_contains($source, "'/^\\d+\\.x(?:\\|\\d+\\.x)*$/'")
            && str_contains($source, "'6.0.11'");
    },
    'release build contract rejects incomplete, unknown, or malformed generated payload declarations' => static function () use ($root, $runToolAt, $withTemporarilyReplacedFixtureFile): bool {
        return sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runToolAt, $withTemporarilyReplacedFixtureFile): bool {
            $path = $fixtureRoot . '/release-build-contract.json';
            $original = file_get_contents($path);
            if (!is_string($original)) return false;
            try {
                $contract = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }
            if (!is_array($contract)) return false;
            $expectFailure = static function (array $replacement) use ($path, $runToolAt, $fixtureRoot, $withTemporarilyReplacedFixtureFile): bool {
                $encoded = json_encode($replacement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                return $withTemporarilyReplacedFixtureFile($path, $encoded, static function () use ($runToolAt, $fixtureRoot): bool {
                    [$status, $output] = $runToolAt($fixtureRoot, []);
                    return $status !== 0 && str_contains($output, 'release build contract locks toolchain and reviewed runtime payloads');
                });
            };
            $missing = $contract;
            unset($missing['generated_payloads']['sdk/typescript/dist']);
            $unknown = $contract;
            $unknown['generated_payloads']['unknown/runtime'] = ['file_count' => 1, 'tree_sha256' => str_repeat('0', 64)];
            $wrongCount = $contract;
            $wrongCount['generated_payloads']['plugin/sand-iam/vendor']['file_count'] = '58';
            $wrongShape = $contract;
            $wrongShape['generated_payloads']['sdk/typescript/dist']['extra'] = true;
            $wrongEnvironment = $contract;
            $wrongEnvironment['composer']['environment']['COMPOSER_ROOT_VERSION'] = '0.7.1';
            $wrongArguments = $contract;
            array_pop($wrongArguments['composer']['arguments']);
            return $expectFailure($missing) && $expectFailure($unknown) && $expectFailure($wrongCount)
                && $expectFailure($wrongShape) && $expectFailure($wrongEnvironment) && $expectFailure($wrongArguments);
        });
    },
    'real worktree provenance fails closed when Git is unavailable' => static function () use ($tool): bool {
        $output = [];
        $command = 'PATH=' . escapeshellarg('/private/tmp/sand-iam-no-git-path')
            . ' ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($tool)
            . ' 2>&1';
        exec($command, $output, $status);
        $text = implode(PHP_EOL, $output);
        return $status !== 0
            && str_contains($text, '[FAIL] eligible release payload is clean, tracked, and matches HEAD Git blobs')
            && !str_contains($text, '[PASS] eligible release payload is clean, tracked, and matches HEAD Git blobs');
    },
    'tool fails closed for missing, malformed, mismatched, or host-incompatible support metadata' => static function () use ($root, $runToolAt, $withTemporarilyReplacedFixtureFile): bool {
        return sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runToolAt, $withTemporarilyReplacedFixtureFile): bool {
            $runTool = static fn (array $arguments): array => $runToolAt($fixtureRoot, $arguments);
            $rootInfo = $fixtureRoot . '/info.ini';
            $packageInfo = $fixtureRoot . '/plugin/sand-iam/info.ini';
            $rootSource = file_get_contents($rootInfo);
            $packageSource = file_get_contents($packageInfo);
            if (!is_string($rootSource) || !is_string($packageSource)) {
                return false;
            }
            $expectFailure = static function (string $replacement) use ($rootInfo, $rootSource, $runTool, $withTemporarilyReplacedFixtureFile): bool {
                return $withTemporarilyReplacedFixtureFile($rootInfo, $replacement, static function () use ($runTool): bool {
                    [$status, $output] = $runTool([]);
                    return $status !== 0 && str_contains($output, 'release metadata versions and host support are consistent');
                });
            };
            return $expectFailure(str_replace("support = 6.x\n", '', $rootSource))
                && $expectFailure(str_replace('support = 6.x', 'support = undefined', $rootSource))
                && $expectFailure(str_replace('support = 6.x', 'support = 6.0', $rootSource))
                && $expectFailure(str_replace('support = 6.x', 'support = 5.x', $rootSource))
                && $expectFailure(str_replace("website = https://saithink.top\n", '', $rootSource))
                && $withTemporarilyReplacedFixtureFile($packageInfo, str_replace('support = 6.x', 'support = 7.x', $packageSource), static function () use ($runTool): bool {
                    [$status, $output] = $runTool([]);
                    return $status !== 0 && str_contains($output, 'release metadata versions and host support are consistent');
                })
                && $withTemporarilyReplacedFixtureFile($packageInfo, str_replace('website = https://saithink.top', 'website = https://invalid.example', $packageSource), static function () use ($runTool): bool {
                    [$status, $output] = $runTool([]);
                    return $status !== 0 && str_contains($output, 'release metadata versions and host support are consistent');
                });
        });
    },
    'tool collects every foreign key from one ALTER TABLE statement' => static function () use ($root, $runToolAt, $withTemporarilyReplacedFixtureFile): bool {
        return sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runToolAt, $withTemporarilyReplacedFixtureFile): bool {
            $base = $fixtureRoot . '/lifecycle/base.pgsql';
            $source = file_get_contents($base);
            if (!is_string($source)) {
                return false;
            }
            $fixture = rtrim($source) . "\n\nALTER TABLE sand_iam_application\n"
                . "    ADD CONSTRAINT fixture_application_organization_fk FOREIGN KEY (id) REFERENCES sand_iam_organization(id),\n"
                . "    ADD CONSTRAINT fixture_application_audit_archive_fk FOREIGN KEY (id) REFERENCES sand_iam_audit_archive(id);\n";
            return $withTemporarilyReplacedFixtureFile($base, $fixture, static function () use ($fixtureRoot, $runToolAt): bool {
                [$status, $output] = $runToolAt($fixtureRoot, []);
                return $status !== 0
                    && str_contains($output, 'uninstall drops principal before dependent without an explicit detached foreign key: sand_iam_application:sand_iam_audit_archive');
            });
        });
    },
    'tool requires every owned table to be explicitly dropped during uninstall' => static function () use ($root, $runToolAt, $withTemporarilyReplacedFixtureFile): bool {
        return sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runToolAt, $withTemporarilyReplacedFixtureFile): bool {
            $rootUninstall = $fixtureRoot . '/uninstall.sql';
            $pluginUninstall = $fixtureRoot . '/plugin/sand-iam/uninstall.sql';
            $rootSource = file_get_contents($rootUninstall);
            $pluginSource = file_get_contents($pluginUninstall);
            if (!is_string($rootSource) || !is_string($pluginSource)) {
                return false;
            }
            $withoutOwnedDrops = static function (string $sql): string {
                return str_replace([
                    "DROP TABLE IF EXISTS sand_iam_audit_archive;\n",
                    "DROP TABLE IF EXISTS sand_iam_oidc_signing_key;\n",
                ], '', $sql);
            };
            return $withTemporarilyReplacedFixtureFile($rootUninstall, $withoutOwnedDrops($rootSource), static function () use ($pluginUninstall, $pluginSource, $withoutOwnedDrops, $withTemporarilyReplacedFixtureFile, $fixtureRoot, $runToolAt): bool {
                return $withTemporarilyReplacedFixtureFile($pluginUninstall, $withoutOwnedDrops($pluginSource), static function () use ($fixtureRoot, $runToolAt): bool {
                    [$status, $output] = $runToolAt($fixtureRoot, []);
                    return $status !== 0
                        && str_contains($output, 'uninstall is missing owned table cleanup: sand_iam_audit_archive, sand_iam_oidc_signing_key');
                });
            });
        });
    },
    'tool reports candidate state, passed total and external release mode' => static function () use ($tool): bool {
        $source = file_get_contents($tool);
        return is_string($source)
            && str_contains($source, '--release')
            && str_contains($source, '--print-candidate-manifest')
            && str_contains($source, '--trusted-manifest=')
            && str_contains($source, '[CANDIDATE]')
            && str_contains($source, 'Package integrity: passed=');
    },
    'tool rejects symbolic release payloads and requires Ed25519 verification' => static function () use ($tool): bool {
        $source = file_get_contents($tool);
        return is_string($source)
            && str_contains($source, 'release artifact payload contains no symbolic links')
            && str_contains($source, 'sodium_crypto_sign_verify_detached')
            && str_contains($source, '--trusted-public-key=')
            && str_contains($source, 'must not contain symbolic links');
    },
    'candidate manifest is explicitly review-only' => static function () use ($runTool): bool {
        [$status, $output] = $runTool(['--print-candidate-manifest']);
        if ($status !== 0) {
            return false;
        }
        try {
            $manifest = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        return is_array($manifest)
            && ($manifest['schema'] ?? null) === 'sand-iam.candidate-manifest/v1'
            && ($manifest['kind'] ?? null) === 'candidate-review-only'
            && is_array($manifest['migrations'] ?? null)
            && is_array($manifest['key_file_hashes'] ?? null);
    },
    'historical recovery descriptor is retained but excluded from normal package payloads' => static function () use ($recoveryDescriptorContract): bool {
        if (!is_file($recoveryDescriptorContract)) {
            return false;
        }
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($recoveryDescriptorContract) . ' 2>&1', $output, $status);
        return $status === 0
            && str_contains(implode(PHP_EOL, $output), 'Historical failed-upgrade recovery descriptor contract: passed=4/4; failed=0');
    },
    'release rejects a missing trusted manifest' => static function () use ($runTool): bool {
        [$status, $output] = $runTool(['--release']);
        return $status !== 0 && str_contains($output, 'missing --trusted-manifest=/path/outside/sand-iam');
    },
    'release rejects a trusted manifest inside the package root' => static function () use ($runTool, $root): bool {
        [$status, $output] = $runTool(['--release', '--trusted-manifest=' . $root . '/info.ini']);
        return $status !== 0 && str_contains($output, '--trusted-manifest=/path/outside/sand-iam must be stored outside the sand-iam package root');
    },
    'release rejects an externally stored manifest whose hash is tampered' => static function () use ($runTool, $withPrivateTempDirectory): bool {
        [$candidateStatus, $candidateOutput] = $runTool(['--print-candidate-manifest']);
        if ($candidateStatus !== 0) {
            return false;
        }
        try {
            $manifest = json_decode($candidateOutput, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (!is_array($manifest)) {
            return false;
        }
        $manifest['schema'] = 'sand-iam.release-provenance/v1';
        $manifest['kind'] = 'external-reviewed-release';
        $manifest['package_sha256'] = str_repeat('0', 64);
        $manifest['provenance'] = [
            'source' => 'negative-test',
            'reference' => 'negative-test',
            'approved_by' => 'negative-test',
            'approved_at' => '2026-08-22T00:00:00Z',
            'signature' => ['algorithm' => 'ed25519', 'value' => base64_encode(str_repeat("\0", 64))],
        ];
        return $withPrivateTempDirectory(static function (string $directory) use ($manifest, $runTool): bool {
            $path = $directory . '/tampered.json';
            if (file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) {
                return false;
            }
            [$status, $output] = $runTool(['--release', '--trusted-manifest=' . $path]);
            return $status !== 0 && str_contains($output, 'trusted manifest package_sha256 differs from the candidate package');
        });
    },
    'release rejects correct hashes with a forged Ed25519 signature' => static function () use ($candidateManifest, $releaseManifest, $withPrivateTempDirectory, $runTool): bool {
        $candidate = $candidateManifest();
        if (!is_array($candidate)) {
            return false;
        }
        $manifest = $releaseManifest($candidate, base64_encode(str_repeat("\0", 64)));
        return $withPrivateTempDirectory(static function (string $directory) use ($manifest, $runTool): bool {
            $manifestPath = $directory . '/forged.json';
            $publicKeyPath = $directory . '/public.key';
            if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false
                || file_put_contents($publicKeyPath, base64_encode(str_repeat("\0", 32))) === false) {
                return false;
            }
            [$status, $output] = $runTool(['--release', '--trusted-manifest=' . $manifestPath, '--trusted-public-key=' . $publicKeyPath]);
            return $status !== 0 && str_contains($output, 'trusted manifest Ed25519 detached signature verification failed');
        });
    },
    'release rejects a symbolic-link trusted manifest path' => static function () use ($withPrivateTempDirectory, $runTool): bool {
        return $withPrivateTempDirectory(static function (string $directory) use ($runTool): bool {
            $target = $directory . '/manifest.json';
            $link = $directory . '/manifest-link.json';
            if (file_put_contents($target, '{}') === false || !symlink($target, $link)) {
                return false;
            }
            [$status, $output] = $runTool(['--release', '--trusted-manifest=' . $link]);
            return $status !== 0 && str_contains($output, '--trusted-manifest=/path/outside/sand-iam path must not contain symbolic links');
        });
    },
    'release rejects symbolic-link and package-internal public keys' => static function () use ($withPrivateTempDirectory, $runTool, $root): bool {
        return $withPrivateTempDirectory(static function (string $directory) use ($runTool, $root): bool {
            $target = $directory . '/public.key';
            $link = $directory . '/public-link.key';
            if (file_put_contents($target, base64_encode(str_repeat("\0", 32))) === false || !symlink($target, $link)) {
                return false;
            }
            [$linkStatus, $linkOutput] = $runTool(['--release', '--trusted-public-key=' . $link]);
            [$internalStatus, $internalOutput] = $runTool(['--release', '--trusted-public-key=' . $root . '/info.ini']);
            return $linkStatus !== 0
                && str_contains($linkOutput, '--trusted-public-key=/path/outside/sand-iam path must not contain symbolic links')
                && $internalStatus !== 0
                && str_contains($internalOutput, '--trusted-public-key=/path/outside/sand-iam must be stored outside the sand-iam package root');
        });
    },
    'release signature verification accepts a valid temporary Ed25519 fixture but still fails dirty release' => static function () use ($candidateManifest, $releaseManifest, $withPrivateTempDirectory, $runTool, $canonicalize): bool {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            return true;
        }
        $candidate = $candidateManifest();
        if (!is_array($candidate)) {
            return false;
        }
        $unsigned = $releaseManifest($candidate, '');
        unset($unsigned['provenance']['signature']);
        $payload = json_encode($canonicalize($unsigned), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $keypair = sodium_crypto_sign_keypair();
        $signature = base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($keypair)));
        $manifest = $releaseManifest($candidate, $signature);
        return $withPrivateTempDirectory(static function (string $directory) use ($manifest, $keypair, $runTool): bool {
            $manifestPath = $directory . '/signed.json';
            $publicKeyPath = $directory . '/public.key';
            if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false
                || file_put_contents($publicKeyPath, base64_encode(sodium_crypto_sign_publickey($keypair))) === false) {
                return false;
            }
            [$status, $output] = $runTool(['--release', '--trusted-manifest=' . $manifestPath, '--trusted-public-key=' . $publicKeyPath]);
            $ok = $status !== 0
                && str_contains($output, 'release mode requires a clean working tree')
                && !str_contains($output, 'trusted manifest Ed25519 detached signature verification failed')
                && !str_contains($output, '[FAIL] release mode requires an external trusted provenance manifest')
                && !str_contains($output, '[FAIL] release mode requires an external Ed25519 public key and valid signature');
            return $ok;
        });
    },
    'candidate rejects a symbolic-link payload file' => static function () use ($root, $runToolAt): bool {
        return sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runToolAt): bool {
            $path = $fixtureRoot . '/migrations/.package-integrity-link.pgsql';
            if (file_exists($path) || is_link($path) || !symlink('/private/tmp', $path)) {
                return false;
            }
            try {
                [$status, $output] = $runToolAt($fixtureRoot, []);
                return $status !== 0 && str_contains($output, 'release artifact payload contains no symbolic links');
            } finally {
                unlink($path);
            }
        });
    },
    'message driver has a single config key' => static function () use ($app): bool {
        $source = file_get_contents($app);
        return is_string($source) && substr_count($source, "'message_drivers' =>") === 1;
    },
    'worker comment no longer claims lifecycle 010 is pending' => static function () use ($process): bool {
        $source = file_get_contents($process);
        return is_string($source) && !str_contains($source, 'candidate worker opt-in until the 010 lifecycle migration');
    },
];

$passed = 0;
foreach ($checks as $label => $check) {
    $ok = is_callable($check) ? $check() : $check;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $passed += $ok ? 1 : 0;
}

$total = count($checks);
echo "Package integrity contract: passed={$passed}/{$total}; failed=" . ($total - $passed) . PHP_EOL;
exit($passed === $total ? 0 : 1);
