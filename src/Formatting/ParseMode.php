<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Formatting;

enum ParseMode: string
{
    case Html = 'HTML';
    case MarkdownV2 = 'MarkdownV2';

    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = is_string($value) ? strtolower(str_replace(['_', '-', ' '], '', $value)) : '';

        return match ($normalized) {
            'markdownv2', 'mdv2' => self::MarkdownV2,
            'html', '' => self::Html,
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported Telegram parse_mode [%s]. Use "HTML" or "MarkdownV2".',
                $value,
            )),
        };
    }
}
