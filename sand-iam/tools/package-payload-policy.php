<?php

declare(strict_types=1);

/**
 * The one authoritative SandIAM installable-payload policy.
 *
 * This file is intentionally a build/checker helper, not package payload. The
 * policy includes only files a host, operator, account user, SDK consumer, or
 * public integration example needs. Development records and build machinery
 * must never influence a release descriptor after a candidate is frozen.
 */

/** @return list<string> */
function sandIamPayloadRoots(): array
{
    return [
        'README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md', 'SBOM.cdx.json', 'release-build-contract.json',
        'config.json', 'info.ini', 'install.sql', 'update.sql', 'uninstall.sql',
        'migrations', 'lifecycle', 'plugin/sand-iam',
        'sandadmin-artd/src/views/plugin/sand-iam', 'portal', 'sdk', 'docs/user-guide', 'examples',
    ];
}

/** @return list<string> */
function sandIamGeneratedDescriptorPaths(): array
{
    return [
        'recovery/failed-upgrade.v2.json',
        'recovery/failed-upgrade.v2.json.sha256',
        'plugin/sand-iam/recovery/failed-upgrade.v2.json',
        'plugin/sand-iam/recovery/failed-upgrade.v2.json.sha256',
    ];
}

function sandIamPayloadExcluded(string $path): bool
{
    // Recovery descriptors describe the historical 0.6.0 -> 0.7.0 repair
    // path. A normal 0.7.1 package must not opt into that host-specific flow.
    if ($path === 'recovery/failed-upgrade.v2.json'
        || $path === 'recovery/failed-upgrade.v2.json.sha256'
        || $path === 'plugin/sand-iam/recovery/failed-upgrade.v2.json'
        || $path === 'plugin/sand-iam/recovery/failed-upgrade.v2.json.sha256') {
        return true;
    }
    // The TypeScript SDK is published as native ESM. Its compiled output is a
    // consumer-facing runtime dependency, unlike every other generated dist
    // tree in this source package. Keep this exception path-scoped: widening
    // the global dist rule would accidentally ship frontend build output,
    // tests, or caches.
    if (str_starts_with($path, 'sdk/typescript/dist/')) {
        return false;
    }
    // The standalone consumer is a copyable source example. Its Composer
    // dependency tree is deliberately local-only and must not bloat or
    // shadow the package's own runtime dependencies.
    if ($path === 'examples/webman-business-app/standalone/vendor'
        || str_starts_with($path, 'examples/webman-business-app/standalone/vendor/')) {
        return true;
    }
    if (in_array($path, [
        'sandadmin-artd/src/views/plugin/sand-iam/getting-started/getting-started.auth-mock.ts',
        'sandadmin-artd/src/views/plugin/sand-iam/getting-started/getting-started.behavior-entry.ts',
        'sandadmin-artd/src/views/plugin/sand-iam/getting-started/getting-started.behavior.ts',
        'sandadmin-artd/src/views/plugin/sand-iam/getting-started/getting-started.http-error-mock.ts',
        'sandadmin-artd/src/views/plugin/sand-iam/getting-started/getting-started.http-mock.ts',
        'sandadmin-artd/src/views/plugin/sand-iam/getting-started/getting-started.index-mount.ts',
        'sandadmin-artd/src/views/plugin/sand-iam/getting-started/getting-started.viewport-entry.ts',
    ], true)) {
        return true;
    }
    return preg_match('#(?:^|/)(?:\.git|\.env(?:\.|$)|node_modules|\.pnpm-store|\.dart_tool|\.DS_Store|\.staging|\.tmp|\.backups|backups|artifacts|coverage|test-results|tests?|dist|build|cache|logs?|waiting-codex)(?:/|$)|(?:^|/)[^/]+\.(?:test|spec)\.[^/]+$|(?:^|/)(?:id_rsa|[^/]+\.(?:pem|key|log|dump))$#i', $path) === 1;
}

/**
 * Canonical form shared by the payload collector and release ZIP verifier.
 *
 * Do not normalize a hostile input here: accepting `a/./b` and then comparing
 * its normalized form would leave different ZIP readers free to disagree. A
 * release path must already be its canonical, portable relative form.
 */
function sandIamCanonicalPayloadPath(string $path): string
{
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
        || preg_match('/^[A-Za-z]:/', $path) === 1
        || preg_match('//u', $path) !== 1
        || preg_match('/\\p{Cc}/u', $path) === 1) {
        throw new RuntimeException('payload path is not a canonical relative path');
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('payload path is not a canonical relative path');
        }
    }
    return $path;
}

/**
 * The release ZIP and Git-object materializer only support normalized relative
 * paths. Keep this boundary shared so a path cannot be accepted by policy but
 * rejected later by candidate construction.
 */
function sandIamPayloadPathSupported(string $path): bool
{
    try {
        sandIamCanonicalPayloadPath($path);
        return true;
    } catch (RuntimeException) {
        return false;
    }
}

/** @return list<string> */
function sandIamPayloadFiles(string $root, bool $includeGeneratedDescriptors): array
{
    $files = [];
    foreach (sandIamPayloadRoots() as $entry) {
        $path = $root . '/' . $entry;
        if (is_link($path)) {
            throw new RuntimeException('payload path must not be symbolic: ' . $entry);
        }
        if (is_file($path)) {
            if (!sandIamPayloadPathSupported($entry)) {
                throw new RuntimeException('payload path contains unsupported characters');
            }
            if (!sandIamPayloadExcluded($entry) && ($includeGeneratedDescriptors || !in_array($entry, sandIamGeneratedDescriptorPaths(), true))) {
                $files[] = $entry;
            }
            continue;
        }
        // Descriptors are generated after the descriptor-excluded stage exists.
        if (!$includeGeneratedDescriptors && $entry === 'recovery' && !file_exists($path)) {
            continue;
        }
        if (!is_dir($path)) {
            throw new RuntimeException('required payload path is missing: ' . $entry);
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $relative = $entry . '/' . substr($file->getPathname(), strlen($path) + 1);
            if (!sandIamPayloadPathSupported($relative)) {
                throw new RuntimeException('payload path contains unsupported characters');
            }
            if (sandIamPayloadExcluded($relative)) {
                continue;
            }
            if ($file->isLink()) {
                throw new RuntimeException('payload tree contains a symbolic link: ' . $relative);
            }
            if ($file->isFile() && ($includeGeneratedDescriptors || !in_array($relative, sandIamGeneratedDescriptorPaths(), true))) {
                $files[] = $relative;
            }
        }
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return $files;
}

/** @return array<string,string> */
function sandIamPayloadFilePaths(string $root, bool $includeGeneratedDescriptors): array
{
    $files = [];
    foreach (sandIamPayloadFiles($root, $includeGeneratedDescriptors) as $relative) {
        $path = $root . '/' . $relative;
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('payload file must be regular: ' . $relative);
        }
        $files[$relative] = $path;
    }
    return $files;
}
