<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Xden\TelegramLogger\Tests\TestCase;

final class TestCommandTest extends TestCase
{
    #[Test]
    public function it_sends_a_test_message_to_the_channel_topic(): void
    {
        $this->fakeTelegram();

        $this->artisan('telegram-logger:test', ['--channel' => 'tg_payments'])
            ->expectsOutputToContain('message_id 42')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['message_thread_id'] === 7
            && str_contains($request['text'], 'Test message from telegram-logger:test'));
    }

    #[Test]
    public function it_never_prints_the_full_token(): void
    {
        $this->fakeTelegram();

        $this->artisan('telegram-logger:test')
            ->expectsOutputToContain('123456789:***')
            ->doesntExpectOutputToContain('AAHdqTcvCH1')
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function apiErrors(): array
    {
        return [
            'chat not found' => [400, 'Bad Request: chat not found', 'Wrong chat_id'],
            'thread not found' => [400, 'Bad Request: message thread not found', 'Wrong thread_id'],
            'unauthorized' => [401, 'Unauthorized', 'bot token is invalid'],
            'no rights' => [403, 'Forbidden: bot is not a member of the supergroup chat; not enough rights', 'not allowed to post'],
        ];
    }

    #[Test]
    #[DataProvider('apiErrors')]
    public function it_explains_api_errors(int $status, string $description, string $hint): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => $status, 'description' => $description], $status)]);

        $this->artisan('telegram-logger:test')
            ->expectsOutputToContain($description)
            ->expectsOutputToContain($hint)
            ->assertFailed();
    }

    #[Test]
    public function it_fails_for_unknown_or_non_telegram_channels(): void
    {
        $this->artisan('telegram-logger:test', ['--channel' => 'nope'])
            ->expectsOutputToContain('is not defined')
            ->assertFailed();

        config()->set('logging.channels.single', ['driver' => 'single', 'path' => '/tmp/x.log']);
        $this->artisan('telegram-logger:test', ['--channel' => 'single'])
            ->expectsOutputToContain('is not a Telegram channel')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_without_credentials(): void
    {
        config()->set('telegram-logger.chat_id', null);

        $this->artisan('telegram-logger:test')
            ->expectsOutputToContain('TELEGRAM_LOGGER_CHAT_ID')
            ->assertFailed();
    }

    #[Test]
    public function it_rejects_unknown_levels(): void
    {
        $this->artisan('telegram-logger:test', ['--level' => 'loud'])->assertFailed();
    }
}
