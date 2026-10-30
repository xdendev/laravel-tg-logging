<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Transport;

use RuntimeException;
use Throwable;

final class TelegramApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $parameters  ResponseParameters from the API
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $description = null,
        public readonly ?int $retryAfter = null,
        public readonly array $parameters = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    public static function fromResponse(int $status, array $body): self
    {
        $code = is_int($body['error_code'] ?? null) ? $body['error_code'] : $status;
        $description = is_string($body['description'] ?? null) ? $body['description'] : 'Unexpected response';
        $parameters = is_array($body['parameters'] ?? null) ? $body['parameters'] : [];
        $retryAfter = is_int($parameters['retry_after'] ?? null) ? $parameters['retry_after'] : null;

        /** @var array<string, mixed> $parameters */
        return new self("Telegram API error {$code}: {$description}", $code, $description, $retryAfter, $parameters);
    }

    public static function connection(string $message, ?Throwable $previous = null): self
    {
        return new self('Could not reach Telegram API: '.$message, previous: $previous);
    }

    public function isConnectionError(): bool
    {
        return $this->status === null;
    }

    public function isRetryable(): bool
    {
        return $this->status === null || $this->status === 429 || $this->status >= 500;
    }

    /**
     * Human readable advice for common configuration mistakes.
     */
    public function hint(): ?string
    {
        $description = strtolower((string) $this->description);

        return match (true) {
            $this->isConnectionError() => 'Check network access to the Telegram API host, TELEGRAM_LOGGER_PROXY and TELEGRAM_LOGGER_TIMEOUT.',
            $this->status === 401 || $this->status === 404 => 'The bot token is invalid or revoked. Get a fresh one from @BotFather (TELEGRAM_LOGGER_BOT_TOKEN).',
            $this->status === 429 => sprintf('Rate limited by Telegram, retry after %d s. Consider enabling "throttle" or the queue.', $this->retryAfter ?? 0),
            str_contains($description, 'chat not found') => 'Wrong chat_id, or the bot was never added to the chat / never received /start. Supergroup ids start with -100.',
            str_contains($description, 'message thread not found') => 'Wrong thread_id: the topic does not exist, or topics are not enabled in this group.',
            str_contains($description, 'topic_closed') => 'The topic is closed. Reopen it or give the bot the "Manage topics" permission.',
            str_contains($description, 'upgraded to a supergroup') => sprintf('The group became a supergroup, use the new chat_id %s.', is_scalar($this->parameters['migrate_to_chat_id'] ?? null) ? (string) $this->parameters['migrate_to_chat_id'] : ''),
            str_contains($description, 'bot was blocked'), str_contains($description, 'bot was kicked') => 'The bot was blocked or removed from the chat. Add it again.',
            str_contains($description, 'not enough rights'), str_contains($description, 'have no rights') => 'The bot is not allowed to post in this chat. Make it an admin or allow members to send messages.',
            str_contains($description, "can't parse entities") => 'Invalid markup. Check parse_mode and your custom template (use $fmt helpers for all dynamic values).',
            str_contains($description, 'message is too long') => 'Lower max_message_length.',
            default => null,
        };
    }
}
