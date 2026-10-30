<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Config;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Monolog\Level;
use Monolog\Logger;
use Throwable;
use Xden\TelegramLogger\Formatting\ParseMode;
use Xden\TelegramLogger\TelegramHandler;
use Xden\TelegramLogger\TelegramLoggerFactory;

/**
 * Effective settings of one Telegram log channel: package defaults from
 * config/telegram-logger.php overridden by the channel's own options.
 */
final class ChannelConfig
{
    private const ALIASES = [
        'message_thread_id' => 'thread_id',
        'bot_token' => 'token',
    ];

    /**
     * Keys of a log channel definition that belong to Laravel, not to us.
     */
    private const RESERVED = ['driver', 'via', 'handler', 'with', 'handler_with', 'level', 'bubble', 'formatter', 'formatter_with', 'tap', 'processors', 'name', 'replace_placeholders', 'action_level'];

    /**
     * @param  array{connection: ?string, queue: ?string}|null  $queue
     * @param  list<string>  $ignore
     * @param  list<string>|null  $environments
     * @param  list<Level>  $silentLevels
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $channel,
        public readonly bool $enabled,
        public readonly ?string $token,
        public readonly ?string $chatId,
        public readonly ?string $threadId,
        public readonly ParseMode $parseMode,
        public readonly ?string $template,
        public readonly float $timeout,
        public readonly ?string $proxy,
        public readonly string $apiHost,
        public readonly int $throttle,
        public readonly ?string $cacheStore,
        public readonly ?array $queue,
        public readonly int $includeTraceLines,
        public readonly bool $includeRequest,
        public readonly bool $includeContext,
        public readonly int $maxMessageLength,
        public readonly int $maxMessages,
        public readonly array $ignore,
        public readonly ?array $environments,
        public readonly array $silentLevels,
        public readonly bool $disableWebPagePreview,
        public readonly array $options,
    ) {}

    /**
     * Build the config from the global package config and channel overrides.
     *
     * @param  array<array-key, mixed>  $overrides
     */
    public static function resolve(array $overrides = [], ?string $channel = null): self
    {
        $defaults = config('telegram-logger');

        return self::fromArrays(is_array($defaults) ? $defaults : [], $overrides, $channel);
    }

    /**
     * Build the config for a channel defined in config/logging.php.
     *
     * @throws InvalidArgumentException
     */
    public static function forLogChannel(string $name): self
    {
        $definition = config("logging.channels.{$name}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Log channel [{$name}] is not defined in config/logging.php.");
        }

        return self::resolve(self::overridesFromChannel($definition, $name), $name);
    }

    /**
     * Extract our options from a log channel definition (any of the
     * supported styles: driver=telegram, driver=custom + via, driver=monolog + handler).
     *
     * @param  array<array-key, mixed>  $definition
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function overridesFromChannel(array $definition, string $name): array
    {
        $driver = $definition['driver'] ?? null;
        $via = $definition['via'] ?? null;
        $handler = $definition['handler'] ?? null;

        if ($driver === 'monolog' && is_string($handler) && is_a($handler, TelegramHandler::class, true)) {
            $with = array_merge(
                is_array($definition['with'] ?? null) ? $definition['with'] : [],
                is_array($definition['handler_with'] ?? null) ? $definition['handler_with'] : [],
            );
            $config = is_array($with['config'] ?? null) ? $with['config'] : [];
            unset($with['config'], $with['level'], $with['bubble']);

            return array_merge($config, $with);
        }

        if ($driver === 'telegram' || ($driver === 'custom' && is_string($via) && is_a($via, TelegramLoggerFactory::class, true))) {
            return self::withoutReserved($definition);
        }

        throw new InvalidArgumentException("Log channel [{$name}] is not a Telegram channel.");
    }

    /**
     * @param  array<array-key, mixed>  $definition
     * @return array<array-key, mixed>
     */
    public static function withoutReserved(array $definition): array
    {
        return array_diff_key($definition, array_flip(self::RESERVED));
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $overrides
     */
    public static function fromArrays(array $defaults, array $overrides, ?string $channel = null): self
    {
        $c = array_merge(self::normalize($defaults), self::normalize($overrides));

        return new self(
            channel: $channel ?? 'telegram',
            enabled: self::bool($c['enabled'] ?? true),
            token: self::string($c['token'] ?? null),
            chatId: self::string($c['chat_id'] ?? null),
            threadId: self::string($c['thread_id'] ?? null),
            parseMode: ParseMode::fromConfig($c['parse_mode'] ?? null),
            template: self::string($c['template'] ?? null),
            timeout: max(0.1, self::float($c['timeout'] ?? 5.5)),
            proxy: self::string($c['proxy'] ?? null),
            apiHost: rtrim(self::string($c['api_host'] ?? null) ?? 'https://api.telegram.org', '/'),
            throttle: max(0, self::int($c['throttle'] ?? 0)),
            cacheStore: self::string($c['cache_store'] ?? null),
            queue: self::queue($c['queue'] ?? null),
            includeTraceLines: max(0, self::int($c['include_trace_lines'] ?? 5)),
            includeRequest: self::bool($c['include_request'] ?? true),
            includeContext: self::bool($c['include_context'] ?? true),
            maxMessageLength: min(4096, max(256, self::int($c['max_message_length'] ?? 4096))),
            maxMessages: max(1, self::int($c['max_messages'] ?? 3)),
            ignore: self::stringList($c['ignore'] ?? []),
            environments: self::stringList($c['environments'] ?? null) ?: null,
            silentLevels: array_map(
                static fn (string $level): Level => Logger::toMonologLevel($level), // @phpstan-ignore argument.type (validated by Monolog)
                self::stringList($c['silent_levels'] ?? []),
            ),
            disableWebPagePreview: self::bool($c['disable_web_page_preview'] ?? true),
            options: self::stringKeyed($c['options'] ?? []),
        );
    }

    public function isConfigured(): bool
    {
        return $this->token !== null && $this->chatId !== null;
    }

    public function isActiveFor(string $environment): bool
    {
        return $this->enabled
            && ($this->environments === null || in_array($environment, $this->environments, true));
    }

    public function isIgnored(Throwable $exception): bool
    {
        foreach ($this->ignore as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public function isSilent(Level $level): bool
    {
        return in_array($level, $this->silentLevels, true);
    }

    /**
     * Parameters of the sendMessage request.
     *
     * @return array<string, mixed>
     */
    public function payload(string $text, bool $silent = false): array
    {
        $payload = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => $this->parseMode->value,
            'disable_notification' => $silent,
        ];

        if ($this->threadId !== null) {
            $payload['message_thread_id'] = ctype_digit($this->threadId) ? (int) $this->threadId : $this->threadId;
        }

        if ($this->disableWebPagePreview) {
            $payload['link_preview_options'] = ['is_disabled' => true];
        }

        return array_merge($payload, $this->options);
    }

    public function maskedToken(): string
    {
        if ($this->token === null) {
            return '(not set)';
        }

        $id = Str::before($this->token, ':');

        return $id === $this->token ? '***' : $id.':***';
    }

    /**
     * Scope for throttle keys, so identical errors in different chats/topics
     * are throttled independently.
     */
    public function destination(): string
    {
        return $this->chatId.'#'.($this->threadId ?? '');
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function normalize(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || $value === null || $value === '') {
                continue;
            }

            $key = Str::snake($key);
            $normalized[self::ALIASES[$key] ?? $key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array{connection: ?string, queue: ?string}|null
     */
    private static function queue(mixed $value): ?array
    {
        if ($value === false || $value === null) {
            return null;
        }

        if ($value === true) {
            return ['connection' => null, 'queue' => null];
        }

        if (is_string($value)) {
            return ['connection' => $value, 'queue' => null];
        }

        if (is_array($value)) {
            return [
                'connection' => self::string($value['connection'] ?? null),
                'queue' => self::string($value['queue'] ?? null),
            ];
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return (bool) $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value);

        return array_values(array_filter($strings, static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
