<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\support;

final class NanoId
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function generate(): string
    {
        $value = '';
        $length = strlen(self::ALPHABET);
        for ($index = 0; $index < 21; $index++) {
            $value .= self::ALPHABET[random_int(0, $length - 1)];
        }
        return $value;
    }
}
