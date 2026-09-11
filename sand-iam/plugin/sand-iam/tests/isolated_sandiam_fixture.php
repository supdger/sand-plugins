<?php

declare(strict_types=1);

/**
 * Copy the testable SandIAM source into a private temporary directory. Negative
 * package tests may mutate only this copy; the authority tree is read-only.
 *
 * @template T
 * @param callable(string):T $callback receives the isolated sand-iam root
 * @return T
 */
function sandIamWithIsolatedFixture(string $authorityRoot, callable $callback): mixed
{
    $seed = tempnam(sys_get_temp_dir(), 'sand-iam-test-fixture-');
    if ($seed === false || !unlink($seed) || !mkdir($seed, 0700)) {
        throw new RuntimeException('cannot create isolated SandIAM test fixture');
    }

    $fixtureRoot = $seed . '/sand-iam';
    $excludedSegments = ['.git', '.pnpm-store', '.dart_tool', 'artifacts', 'build', 'coverage', 'dist', 'node_modules', 'test-results', 'tests'];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($authorityRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($authorityRoot) + 1);
            $segments = explode(DIRECTORY_SEPARATOR, $relative);
            // The published TypeScript SDK is native ESM; unlike frontend
            // build output, its dist tree is part of the installable payload.
            if (!str_starts_with($relative, 'sdk/typescript/dist/')
                && array_intersect($segments, $excludedSegments) !== []) {
                continue;
            }
            $target = $fixtureRoot . DIRECTORY_SEPARATOR . $relative;
            if ($entry->isLink()) {
                throw new RuntimeException('authority fixture source contains symbolic link: ' . $relative);
            }
            if ($entry->isDir()) {
                if (!mkdir($target, 0700, true) && !is_dir($target)) {
                    throw new RuntimeException('cannot create fixture directory: ' . $relative);
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new RuntimeException('cannot create fixture parent directory: ' . $relative);
            }
            if (!copy($entry->getPathname(), $target)) {
                throw new RuntimeException('cannot copy fixture file: ' . $relative);
            }
        }
        return $callback($fixtureRoot);
    } finally {
        if (is_dir($seed)) {
            $cleanup = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($seed, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($cleanup as $entry) {
                if ($entry instanceof SplFileInfo) {
                    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
            }
            rmdir($seed);
        }
    }
}

/** @return array<string,string> */
function sandIamAuthorityPayloadSnapshot(string $authorityRoot): array
{
    require_once $authorityRoot . '/tools/package-payload-policy.php';
    $snapshot = [];
    foreach (sandIamPayloadFilePaths($authorityRoot, true) as $relative => $path) {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('cannot hash authority payload: ' . $relative);
        }
        $snapshot[$relative] = $hash;
    }
    foreach (sandIamGeneratedDescriptorPaths() as $relative) {
        $path = $authorityRoot . '/' . $relative;
        $snapshot[$relative] = is_file($path) ? (string) hash_file('sha256', $path) : '__missing__';
    }
    ksort($snapshot, SORT_STRING);
    return $snapshot;
}
