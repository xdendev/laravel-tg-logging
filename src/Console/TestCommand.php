<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Monolog\Logger;
use Monolog\LogRecord;
use RuntimeException;
use Throwable;
use Xden\TelegramLogger\Config\ChannelConfig;
use Xden\TelegramLogger\Formatting\MessageFormatter;
use Xden\TelegramLogger\Formatting\MessageSplitter;
use Xden\TelegramLogger\Transport\TelegramApiException;
use Xden\TelegramLogger\Transport\TelegramClient;

final class TestCommand extends Command
{
    protected $signature = 'telegram-logger:test
        {--channel= : Log channel from config/logging.php (defaults to config/telegram-logger.php)}
        {--level=error : Level of the test message}
        {--message= : Custom message text}';

    protected $description = 'Send a test message to Telegram to verify the logger configuration';

    public function handle(TelegramClient $client, MessageFormatter $formatter, MessageSplitter $splitter): int
    {
        $channel = $this->option('channel');

        try {
            $config = is_string($channel) && $channel !== ''
                ? ChannelConfig::forLogChannel($channel)
                : ChannelConfig::resolve();
            $level = $this->option('level');
            $level = Logger::toMonologLevel(is_string($level) ? $level : 'error'); // @phpstan-ignore argument.type (validated by Monolog)
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Channel', $config->channel);
        $this->components->twoColumnDetail('Bot token', $config->maskedToken());
        $this->components->twoColumnDetail('Chat ID', $config->chatId ?? '(not set)');
        $this->components->twoColumnDetail('Thread ID', $config->threadId ?? '(none)');
        $this->components->twoColumnDetail('Parse mode', $config->parseMode->value);
        $this->components->twoColumnDetail('Delivery', $config->queue === null ? 'sync' : 'queue '.($config->queue['connection'] ?? 'default'));
        $this->components->twoColumnDetail('Enabled', $config->enabled ? 'yes' : 'no');
        $this->newLine();

        if (! $config->isConfigured() || $config->token === null) {
            $this->components->error('Bot token and chat id are required. Set TELEGRAM_LOGGER_BOT_TOKEN and TELEGRAM_LOGGER_CHAT_ID (or token / chat_id on the channel).');

            return self::FAILURE;
        }

        if (! $config->enabled) {
            $this->components->warn('Telegram logging is disabled (TELEGRAM_LOGGER_ENABLED). Sending the test message anyway.');
        }

        $message = $this->option('message');
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: $config->channel,
            level: $level,
            message: is_string($message) && $message !== '' ? $message : 'Test message from telegram-logger:test',
            context: ['exception' => new RuntimeException('This is a test exception, everything works.')],
        );

        $result = [];

        try {
            $text = $formatter->format($record, $config);
            $chunks = $splitter->split($text, $config->parseMode, $config->maxMessageLength, $config->maxMessages);

            // always synchronous and unthrottled, so errors are visible here
            foreach ($chunks as $chunk) {
                $result = $client->sendMessage($config->token, $config->payload($chunk), $config->timeout, $config->proxy, $config->apiHost);
            }
        } catch (TelegramApiException $e) {
            $this->components->error($e->getMessage());

            if (($hint = $e->hint()) !== null) {
                $this->line('  <fg=yellow>Hint:</> '.$hint);
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }

        $messageId = $result['message_id'] ?? null;
        $this->components->info('Message sent'.(is_scalar($messageId) ? " (message_id {$messageId})" : '').'.');

        return self::SUCCESS;
    }
}
