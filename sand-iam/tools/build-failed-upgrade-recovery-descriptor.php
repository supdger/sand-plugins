<?php

declare(strict_types=1);

/**
 * Historical 0.7.0 recovery descriptors are retained as evidence only.
 *
 * SandIAM 0.7.1 normal packages deliberately do not opt into the old
 * 0.6.0 -> 0.7.0 failed-upgrade flow, so this command must never rewrite a
 * descriptor against current metadata or lifecycle bytes.
 */

$root = dirname(__DIR__);
$rootDescriptor = $root . '/recovery/failed-upgrade.v2.json';
$packageDescriptor = $root . '/plugin/sand-iam/recovery/failed-upgrade.v2.json';
$rootBytes = is_file($rootDescriptor) ? file_get_contents($rootDescriptor) : false;
$packageBytes = is_file($packageDescriptor) ? file_get_contents($packageDescriptor) : false;
if (!is_string($rootBytes) || $rootBytes === '' || $rootBytes !== $packageBytes) {
    throw new RuntimeException('historical 0.7.0 recovery descriptor evidence is missing or no longer mirrored');
}

fwrite(STDERR, "SandIAM 0.7.1 excludes failed-upgrade recovery descriptors from normal packages; historical 0.7.0 evidence was not rewritten\n");
exit(2);
