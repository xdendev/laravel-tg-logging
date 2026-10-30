<?php

declare(strict_types=1);

namespace Xden\TelegramLogger;

use Illuminate\Container\Container;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use Xden\TelegramLogger\Config\ChannelConfig;
use Xden\TelegramLogger\Formatting\MessageFormatter;
use Xden\TelegramLogger\Formatting\MessageSplitter;
use Xden\TelegramLogger\Support\FailureReporter;
use Xden\TelegramLogger\Support\Throttler;
use Xden\TelegramLogger\Transport\MessageSender;

/**
 * Monolog handler that sends records to a Telegram chat or forum topic.
 *
 * Options that are not passed fall back to config/telegram-logger.php.
 * The handler never throws.
 */
class TelegramHandler extends AbstractProcessingHandler
{
    private static bool $sending = false;

    /** @var array<array-key, mixed> */
    private array $overrides;

    /**
     * @param  array{connection?: ?string, queue?: ?string}|bool|string|null  $queue  null inherits, false disables
     * @param  array<array-key, mixed>  $config  any other option of config/telegram-logger.php
     */
    public function __construct(
        ?string $token = null,
        int|string|null $chatId = null,
        int|string|null $threadId = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?string $parseMode = null,
        int|string|null $throttle = null,
        array|bool|string|null $queue = null,
        float|int|string|null $timeout = null,
        array $config = [],
    ) {
        parent::__construct($level, $bubble);

        $explicit = array_filter(
            compact('token', 'chatId', 'threadId', 'parseMode', 'throttle', 'queue', 'timeout'),
            static fn (mixed $value): bool => $value !== null,
        );

        $this->overrides = array_merge($config, $explicit);
    }

    /**
     * Create the handler from a log channel definition (driver "telegram" or "custom").
     *
     * @param  array<array-key, mixed>  $channel
     */
    public static function fromChannel(array $channel): self
    {
        $level = $channel['level'] ?? Level::Debug;

        $handler = new self(
            level: is_int($level) || is_string($level) || $level instanceof Level ? $level : Level::Debug, // @phpstan-ignore argument.type (validated by Monolog)
            bubble: (bool) ($channel['bubble'] ?? true),
            config: ChannelConfig::withoutReserved($channel),
        );

        if (is_string($channel['name'] ?? null)) {
            $handler->overrides['channel'] = $channel['name'];
        }

        return $handler;
    }

    public function channelConfig(): ChannelConfig
    {
        $channel = $this->overrides['channel'] ?? null;

        return ChannelConfig::resolve($this->overrides, is_string($channel) ? $channel : null);
    }

    protected function write(LogRecord $record): void
    {
        // a log call made while sending (e.g. from a template or HTTP listener) must not loop
        if (self::$sending) {
            return;
        }

        self::$sending = true;

        try {
            $this->send($record);
        } catch (Throwable $e) {
            $token = $this->overrides['token'] ?? null;

            try {
                Container::getInstance()->make(FailureReporter::class)
                    ->report('Telegram handler failed', $e, is_string($token) ? $token : null);
            } catch (Throwable) {
                error_log('[telegram-logger] '.FailureReporter::mask($e->getMessage()));
            }
        } finally {
            self::$sending = false;
        }
    }

    protected function send(LogRecord $record): void
    {
        $app = Container::getInstance();
        $config = $this->channelConfig();

        if (! $config->enabled) {
            return;
        }

        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof Throwable && $config->isIgnored($exception)) {
            return;
        }

        if (! $config->isConfigured()) {
            $app->make(FailureReporter::class)->report(
                "Channel [{$config->channel}] has no token or chat_id. Set TELEGRAM_LOGGER_BOT_TOKEN and TELEGRAM_LOGGER_CHAT_ID."
            );

            return;
        }

        if (! $app->make(Throttler::class)->attempt($this->throttleKey($record, $config), $config->throttle, $config->cacheStore)) {
            return;
        }

        $text = $app->make(MessageFormatter::class)->format($record, $config);
        $chunks = $app->make(MessageSplitter::class)->split($text, $config->parseMode, $config->maxMessageLength, $config->maxMessages);

        $app->make(MessageSender::class)->send($config, $chunks, $config->isSilent($record->level));
    }

    protected function throttleKey(LogRecord $record, ChannelConfig $config): string
    {
        $exception = $record->context['exception'] ?? null;

        $event = $exception instanceof Throwable
            ? $exception::class.'@'.$exception->getFile().':'.$exception->getLine()
            : $record->level->getName().'|'.$record->message;

        return $config->destination().'|'.$event;
    }
}
