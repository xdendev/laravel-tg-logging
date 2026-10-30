<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Support;

use Closure;
use Throwable;

/**
 * Reports internal failures to PHP's error_log instead of the Laravel log,
 * so a broken Telegram channel can never cause logging recursion.
 */
class FailureReporter
{
    /** @var (Closure(string): void)|null */
    private ?Closure $writer = null;

    public function report(string $message, ?Throwable $exception = null, ?string $token = null): void
    {
        $line = '[telegram-logger] '.$message;

        if ($exception !== null) {
            $line .= ': '.$exception::class.': '.$exception->getMessage();
        }

        $line = self::mask($line, $token);

        try {
            $this->writer !== null ? ($this->writer)($line) : error_log($line);
        } catch (Throwable) {
            // nothing else we can do
        }
    }

    /**
     * Replace the error_log writer, e.g. in tests.
     *
     * @param  (Closure(string): void)|null  $writer
     */
    public function writeUsing(?Closure $writer): void
    {
        $this->writer = $writer;
    }

    /**
     * Remove bot tokens from a string.
     */
    public static function mask(string $text, ?string $token = null): string
    {
        if ($token !== null && $token !== '') {
            $text = str_replace([$token, rawurlencode($token)], '***', $text);
        }

        return (string) preg_replace('~\d{5,}:[A-Za-z0-9_-]{20,}~', '***', $text);
    }
}
