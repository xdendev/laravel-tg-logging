<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Xden\TelegramLogger\Support\FailureReporter;
use Xden\TelegramLogger\Transport\TelegramApiException;
use Xden\TelegramLogger\Transport\TelegramClient;

/**
 * Sends one message. The payload is encrypted because it carries the bot token.
 *
 * The job never throws: a failing job would be reported by the queue worker,
 * logged to Telegram again and fail again. Retryable errors (network, 5xx,
 * 429) are released back to the queue, honouring retry_after.
 */
final class SendTelegramMessageJob implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $token,
        public readonly array $payload,
        public readonly float $timeout = 5.5,
        public readonly ?string $proxy = null,
        public readonly string $apiHost = 'https://api.telegram.org',
    ) {}

    public function handle(TelegramClient $client, FailureReporter $reporter): void
    {
        try {
            $client->sendMessage($this->token, $this->payload, $this->timeout, $this->proxy, $this->apiHost);
        } catch (TelegramApiException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $this->release($e->retryAfter ?? $this->delayFor($this->attempts()));

                return;
            }

            $reporter->report(sprintf('Failed to send queued message after %d attempt(s)', $this->attempts()), $e, $this->token);
        }
    }

    private function delayFor(int $attempt): int
    {
        return $this->backoff[min($attempt, count($this->backoff)) - 1] ?? 60;
    }
}
