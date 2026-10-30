<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Formatting;

/**
 * Telegram measures message length in UTF-16 code units.
 */
final class Length
{
    public static function of(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Longest prefix of $text that fits into $limit units.
     */
    public static function prefix(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        $prefix = mb_substr($text, 0, $limit, 'UTF-8');

        // a code point is 1 or 2 units, so dropping ceil(excess / 2) of them never overshoots
        while (($excess = self::of($prefix) - $limit) > 0) {
            $prefix = mb_substr($prefix, 0, mb_strlen($prefix, 'UTF-8') - intdiv($excess + 1, 2), 'UTF-8');
        }

        return $prefix;
    }
}
