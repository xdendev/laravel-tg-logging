<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Formatting;

/**
 * Splits rendered markup into messages that fit the Telegram limit without
 * breaking tags, entities or escape sequences.
 */
final class MessageSplitter
{
    private const MIN_LIMIT = 64;

    private const HTML_TOKEN = '~(</?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*)?>|&(?:#\d+|#x[0-9a-fA-F]+|[a-zA-Z]+);)~';

    private const HTML_ENTITY = '~^&(?:#\d+|#x[0-9a-fA-F]+|[a-zA-Z]+);$~';

    private const MARKDOWN_LINK = '~\G\[(?:[^\]\\\\]|\\\\.)*\]\((?:[^)\\\\]|\\\\.)*\)~s';

    private const MARKDOWN_TOGGLES = ['||', '__', '*', '_', '~'];

    /**
     * @return list<string>
     */
    public function split(string $text, ParseMode $mode, int $limit = 4096, int $maxMessages = 1): array
    {
        $limit = max(self::MIN_LIMIT, $limit);
        $maxMessages = max(1, $maxMessages);

        $chunks = $this->pack($text, $mode, $limit);

        if (count($chunks) <= $maxMessages) {
            return $chunks;
        }

        $marker = (new Markup($mode))->italic('… truncated');
        $kept = array_slice($chunks, 0, $maxMessages - 1);
        $last = $this->pack($chunks[$maxMessages - 1], $mode, $limit - Length::of($marker) - 1)[0] ?? '';
        $kept[] = $last."\n".$marker;

        return $kept;
    }

    /**
     * @return list<string>
     */
    private function pack(string $text, ParseMode $mode, int $limit): array
    {
        $builder = new ChunkBuilder($limit);

        match ($mode) {
            ParseMode::Html => $this->feedHtml($builder, $text),
            ParseMode::MarkdownV2 => $this->feedMarkdown($builder, $text),
        };

        return $builder->finish();
    }

    private function feedHtml(ChunkBuilder $builder, string $text): void
    {
        $parts = preg_split(self::HTML_TOKEN, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];

        foreach ($parts as $part) {
            if (preg_match('~^</([a-zA-Z][a-zA-Z0-9-]*)\s*>$~', $part, $m)) {
                $builder->close(strtolower($m[1]));
            } elseif (preg_match('~^<([a-zA-Z][a-zA-Z0-9-]*)(?:\s[^<>]*)?>$~', $part, $m)) {
                $name = strtolower($m[1]);
                $builder->open($name, $part, '</'.$name.'>');
            } elseif (preg_match(self::HTML_ENTITY, $part)) {
                $builder->atom($part);
            } else {
                $builder->text($part);
            }
        }
    }

    private function feedMarkdown(ChunkBuilder $builder, string $text): void
    {
        $buffer = '';
        $open = [];
        $inPre = false;
        $inCode = false;
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $char = $text[$i];

            if ($char === '\\' && $i + 1 < $length) {
                preg_match('~\G\\\\.~us', $text, $m, 0, $i);
                $escape = $m[0] ?? substr($text, $i, 2);
                $this->flushText($builder, $buffer);
                $builder->atom($escape);
                $i += strlen($escape);

                continue;
            }

            if (! $inCode && substr($text, $i, 3) === '```') {
                $this->flushText($builder, $buffer);

                if ($inPre) {
                    $builder->close('pre');
                    $inPre = false;
                    $i += 3;

                    continue;
                }

                $newline = strpos($text, "\n", $i + 3);
                $header = $newline === false ? '```' : substr($text, $i, $newline - $i + 1);
                $builder->open('pre', $header, '```');
                $inPre = true;
                $i += strlen($header);

                continue;
            }

            if ($inPre) {
                $buffer .= $char;
                $i++;

                continue;
            }

            if ($char === '`') {
                $this->flushText($builder, $buffer);
                $inCode ? $builder->close('code') : $builder->open('code', '`', '`');
                $inCode = ! $inCode;
                $i++;

                continue;
            }

            if ($inCode) {
                $buffer .= $char;
                $i++;

                continue;
            }

            if ($char === '[' && preg_match(self::MARKDOWN_LINK, $text, $m, 0, $i)) {
                $this->flushText($builder, $buffer);
                $builder->atom($m[0]);
                $i += strlen($m[0]);

                continue;
            }

            foreach (self::MARKDOWN_TOGGLES as $marker) {
                if (substr($text, $i, strlen($marker)) === $marker) {
                    $this->flushText($builder, $buffer);

                    if (isset($open[$marker])) {
                        unset($open[$marker]);
                        $builder->close($marker);
                    } else {
                        $open[$marker] = true;
                        $builder->open($marker, $marker, $marker);
                    }

                    $i += strlen($marker);

                    continue 2;
                }
            }

            $buffer .= $char;
            $i++;
        }

        $this->flushText($builder, $buffer);
    }

    private function flushText(ChunkBuilder $builder, string &$buffer): void
    {
        if ($buffer !== '') {
            $builder->text($buffer);
            $buffer = '';
        }
    }
}
