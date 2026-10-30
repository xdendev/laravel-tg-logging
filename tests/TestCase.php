<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Xden\TelegramLogger\Support\FailureReporter;
use Xden\TelegramLogger\TelegramHandler;
use Xden\TelegramLogger\TelegramLoggerFactory;
use Xden\TelegramLogger\TelegramLoggerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    /** @var list<string> */
    protected array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->failures = [];
        $this->app->make(FailureReporter::class)->writeUsing(function (string $line): void {
            $this->failures[] = $line;
        });
    }

    protected function getPackageProviders($app): array
    {
        return [TelegramLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Shop');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');

        $app['config']->set('telegram-logger.token', self::TOKEN);
        $app['config']->set('telegram-logger.chat_id', '-1001234567890');
        $app['config']->set('telegram-logger.include_request', false);

        $app['config']->set('logging.channels.telegram', [
            'driver' => 'telegram',
            'level' => 'debug',
        ]);

        $app['config']->set('logging.channels.tg_payments', [
            'driver' => 'telegram',
            'thread_id' => '7',
            'level' => 'warning',
        ]);

        $app['config']->set('logging.channels.tg_handler', [
            'driver' => 'monolog',
            'handler' => TelegramHandler::class,
            'with' => [
                'chatId' => '-1009999',
                'threadId' => 12,
                'level' => 'error',
            ],
        ]);

        $app['config']->set('logging.channels.tg_custom', [
            'driver' => 'custom',
            'via' => TelegramLoggerFactory::class,
            'chat_id' => '555',
            'token' => '987654321:BBHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw',
            'level' => 'debug',
        ]);
    }

    protected function fakeTelegram(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]]),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sentPayloads(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): array => $pair[0]->data())
            ->values()
            ->all();
    }

    protected function lastText(): string
    {
        $payloads = $this->sentPayloads();
        $this->assertNotEmpty($payloads, 'Nothing was sent to Telegram');

        return (string) end($payloads)['text'];
    }

    protected static function isSendMessage(Request $request, string $token = self::TOKEN): bool
    {
        return $request->url() === "https://api.telegram.org/bot{$token}/sendMessage" && $request->method() === 'POST';
    }
}
