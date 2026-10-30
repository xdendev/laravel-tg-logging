<?php

declare(strict_types=1);

namespace Xden\TelegramLogger;

use Illuminate\Contracts\Container\Container;
use Throwable;
use Xden\TelegramLogger\Config\ChannelConfig;
use Xden\TelegramLogger\Formatting\Markup;
use Xden\TelegramLogger\Formatting\MessageSplitter;
use Xden\TelegramLogger\Formatting\ParseMode;
use Xden\TelegramLogger\Support\FailureReporter;
use Xden\TelegramLogger\Transport\MessageSender;

/**
 * Manual alerts outside of Monolog, see the TelegramLog facade.
 */
class TelegramNotifier
{
    public function __construct(private readonly Container $app) {}

    /**
     * Send a message. Plain text is escaped unless $escape is false, in which
     * case $text must be valid markup for the channel's parse_mode.
     *
     * @param  string|null  $channel  a log channel from config/logging.php to take settings from
     * @return bool false when sending is disabled or failed (never throws)
     */
    public function send(
        string $text,
        int|string|null $threadId = null,
        int|string|null $chatId = null,
        ?string $channel = null,
        bool $escape = true,
        bool $silent = false,
    ): bool {
        try {
            $config = $this->config($channel, array_filter(
                ['thread_id' => $threadId, 'chat_id' => $chatId],
                static fn (mixed $value): bool => $value !== null,
            ));

            if (! $config->enabled) {
                return false;
            }

            if ($escape) {
                $text = (new Markup($config->parseMode))->text($text);
            }

            $chunks = $this->app->make(MessageSplitter::class)
                ->split($text, $config->parseMode, $config->maxMessageLength, $config->maxMessages);

            return $this->app->make(MessageSender::class)->send($config, $chunks, $silent);
        } catch (Throwable $e) {
            $this->app->make(FailureReporter::class)->report('TelegramLog::send() failed', $e);

            return false;
        }
    }

    /**
     * Escape text for the given (or configured) parse mode.
     */
    public function escape(string $text, ParseMode|string|null $parseMode = null): string
    {
        $mode = ParseMode::fromConfig($parseMode ?? ChannelConfig::resolve()->parseMode);

        return (new Markup($mode))->text($text);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function config(?string $channel = null, array $overrides = []): ChannelConfig
    {
        if ($channel === null) {
            return ChannelConfig::resolve($overrides);
        }

        $base = ChannelConfig::overridesFromChannel((array) config("logging.channels.{$channel}"), $channel);

        return ChannelConfig::resolve(array_merge($base, $overrides), $channel);
    }
}
