<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Transport;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;
use Xden\TelegramLogger\Support\FailureReporter;

/**
 * Thin wrapper around the sendMessage method of the Bot API.
 */
class TelegramClient
{
    /**
     * When no factory is given the current one is taken from the container
     * on every call, so Http::fake() works as expected.
     */
    public function __construct(private readonly ?HttpFactory $http = null) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed> The sent Message object
     *
     * @throws TelegramApiException
     */
    public function sendMessage(
        string $token,
        array $payload,
        float $timeout = 5.5,
        ?string $proxy = null,
        string $apiHost = 'https://api.telegram.org',
    ): array {
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => min($timeout, 3.0),
        ];

        if ($proxy !== null && $proxy !== '') {
            $options['proxy'] = $proxy;
        }

        try {
            $response = $this->http()
                ->acceptJson()
                ->withOptions($options)
                ->post(rtrim($apiHost, '/').'/bot'.$token.'/sendMessage', $payload);
        } catch (Throwable $e) {
            throw TelegramApiException::connection(FailureReporter::mask($e->getMessage(), $token));
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            return is_array($body['result'] ?? null) ? $body['result'] : [];
        }

        throw TelegramApiException::fromResponse($response->status(), $body);
    }

    private function http(): HttpFactory
    {
        return $this->http ?? Container::getInstance()->make(HttpFactory::class);
    }
}
