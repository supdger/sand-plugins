<?php

declare(strict_types=1);

/**
 * Reuse a published migration body inside a lifecycle-owned transaction.
 *
 * The migration source remains byte-for-byte immutable. Only its outer
 * transaction delimiters are omitted from the generated lifecycle so a
 * preceding admission preflight and the migration body share one executor
 * visible transaction.
 */
function withoutOuterTransaction(string $sql, string $name): string
{
    $beginOffset = strpos($sql, "\nBEGIN;");
    $commitOffset = strrpos($sql, "\nCOMMIT;");
    if ($beginOffset === false || $commitOffset === false || $beginOffset >= $commitOffset
        || trim(substr($sql, $commitOffset + strlen("\nCOMMIT;"))) !== '') {
        throw new RuntimeException("Migration {$name} must own one terminal explicit transaction");
    }

    return rtrim(
        substr($sql, 0, $beginOffset + 1)
        . substr($sql, $beginOffset + strlen("\nBEGIN;"), $commitOffset - ($beginOffset + strlen("\nBEGIN;")))
    ) . "\n";
}

