<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Facades;

use Illuminate\Support\Facades\Facade;
use Xden\TelegramLogger\Config\ChannelConfig;
use Xden\TelegramLogger\Formatting\ParseMode;
use Xden\TelegramLogger\TelegramNotifier;

/**
 * @method static bool send(string $text, int|string|null $threadId = null, int|string|null $chatId = null, ?string $channel = null, bool $escape = true, bool $silent = false)
 * @method static string escape(string $text, ParseMode|string|null $parseMode = null)
 * @method static ChannelConfig config(?string $channel = null, array<string, mixed> $overrides = [])
 *
 * @see TelegramNotifier
 */
final class TelegramLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelegramNotifier::class;
    }
}
