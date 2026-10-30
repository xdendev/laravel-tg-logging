<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Transport;

use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;
use Xden\TelegramLogger\Config\ChannelConfig;
use Xden\TelegramLogger\Jobs\SendTelegramMessageJob;
use Xden\TelegramLogger\Support\FailureReporter;
use Xden\TelegramLogger\Support\Throttler;

/**
 * Delivers prepared messages synchronously or through the queue.
 * Never throws: failures go to the FailureReporter.
 */
class MessageSender
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly Throttler $throttler,
        private readonly FailureReporter $reporter,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * @param  list<string>  $chunks
     * @return bool true when every chunk was sent or queued
     */
    public function send(ChannelConfig $config, array $chunks, bool $silent = false): bool
    {
        if ($chunks === []) {
            return true;
        }

        if ($config->token === null || $config->chatId === null) {
            $this->reporter->report("Channel [{$config->channel}] has no token or chat_id configured");

            return false;
        }

        if ($config->queue !== null && $this->dispatch($config, $config->token, $chunks, $silent)) {
            return true;
        }

        return $this->sendNow($config, $config->token, $chunks, $silent);
    }

    /**
     * @param  list<string>  $chunks
     */
    private function sendNow(ChannelConfig $config, string $token, array $chunks, bool $silent): bool
    {
        $cooldownKey = 'token:'.$token;

        if ($this->throttler->coolingDown($cooldownKey, $config->cacheStore)) {
            $this->reporter->report('Rate limited by Telegram, message dropped');

            return false;
        }

        // the whole record shares one time budget, so logging never blocks longer than "timeout"
        $deadline = microtime(true) + $config->timeout;

        foreach ($chunks as $index => $chunk) {
            $remaining = $deadline - microtime(true);

            if ($remaining < 0.1) {
                $this->reporter->report(sprintf('Timeout budget exhausted, %d of %d parts dropped', count($chunks) - $index, count($chunks)));

                return false;
            }

            try {
                $this->client->sendMessage($token, $config->payload($chunk, $silent), $remaining, $config->proxy, $config->apiHost);
            } catch (TelegramApiException $e) {
                if ($e->retryAfter !== null) {
                    $this->throttler->cooldown($cooldownKey, $e->retryAfter, $config->cacheStore);
                }

                $this->reporter->report("Failed to send message to Telegram (channel [{$config->channel}])", $e, $token);

                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $chunks
     */
    private function dispatch(ChannelConfig $config, string $token, array $chunks, bool $silent): bool
    {
        try {
            $jobs = array_map(
                static fn (string $chunk): SendTelegramMessageJob => new SendTelegramMessageJob(
                    $token, $config->payload($chunk, $silent), $config->timeout, $config->proxy, $config->apiHost,
                ),
                $chunks,
            );

            $first = array_shift($jobs);

            if ($first === null) {
                return true;
            }

            $first->chain($jobs)
                ->allOnConnection($config->queue['connection'] ?? null)
                ->allOnQueue($config->queue['queue'] ?? null);

            $this->bus->dispatch($first);

            return true;
        } catch (Throwable $e) {
            $this->reporter->report('Failed to queue Telegram message, sending synchronously', $e, $token);

            return false;
        }
    }
}
