<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Formatting;

/**
 * Markup helpers for the selected parse mode. Every method escapes its
 * argument, so templates can pass raw user data: {!! $fmt->bold($appName) !!}.
 */
final class Markup
{
    private const MARKDOWN_SPECIAL = ['\\', '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

    public function __construct(public readonly ParseMode $mode = ParseMode::Html) {}

    public function text(string $text): string
    {
        $text = self::scrub($text);

        return match ($this->mode) {
            ParseMode::Html => htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ParseMode::MarkdownV2 => str_replace(
                self::MARKDOWN_SPECIAL,
                array_map(static fn (string $char): string => '\\'.$char, self::MARKDOWN_SPECIAL),
                $text,
            ),
        };
    }

    public function bold(string $text): string
    {
        return $this->wrap($text, '<b>', '</b>', '*');
    }

    public function italic(string $text): string
    {
        return $this->wrap($text, '<i>', '</i>', '_');
    }

    public function code(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return match ($this->mode) {
            ParseMode::Html => '<code>'.$this->text($text).'</code>',
            ParseMode::MarkdownV2 => '`'.$this->codeText($text).'`',
        };
    }

    public function pre(string $text, ?string $language = null): string
    {
        if ($text === '') {
            return '';
        }

        $language = $language !== null ? preg_replace('/[^\w+-]/', '', $language) : null;

        return match ($this->mode) {
            ParseMode::Html => $language
                ? '<pre><code class="language-'.$language.'">'.$this->text($text).'</code></pre>'
                : '<pre>'.$this->text($text).'</pre>',
            ParseMode::MarkdownV2 => '```'.$language."\n".$this->codeText($text)."\n```",
        };
    }

    public function link(string $text, string $url): string
    {
        return match ($this->mode) {
            ParseMode::Html => '<a href="'.htmlspecialchars(self::scrub($url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$this->text($text).'</a>',
            ParseMode::MarkdownV2 => '['.$this->text($text).']('.str_replace(['\\', ')'], ['\\\\', '\\)'], self::scrub($url)).')',
        };
    }

    /**
     * Drop invalid UTF-8 sequences, Telegram rejects such messages.
     */
    public static function scrub(string $text): string
    {
        return mb_scrub($text, 'UTF-8');
    }

    private function wrap(string $text, string $open, string $close, string $marker): string
    {
        if ($text === '') {
            return '';
        }

        return match ($this->mode) {
            ParseMode::Html => $open.$this->text($text).$close,
            ParseMode::MarkdownV2 => $marker.$this->text($text).$marker,
        };
    }

    private function codeText(string $text): string
    {
        return str_replace(['\\', '`'], ['\\\\', '\\`'], self::scrub($text));
    }
}
