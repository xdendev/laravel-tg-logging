<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Xden\TelegramLogger\Config\ChannelConfig;
use Xden\TelegramLogger\Tests\TestCase;

final class ConfigTest extends TestCase
{
    private const LEGACY = [
        'TELEGRAM_LOGGER_BOT_TOKEN' => '1:legacy',
        'TELEGRAM_LOGGER_CHAT_ID' => '-100500',
        'TELEGRAM_LOGGER_MESSAGE_THREAD_ID' => '9',
        'TELEGRAM_LOGGER_TIMEOUT' => '3',
        'TELEGRAM_LOGGER_PROXY' => 'proxy.local:3128',
        'TELEGRAM_LOGGER_TEMPLATE' => 'laravel-telegram-logging::minimal',
    ];

    protected function tearDown(): void
    {
        foreach (array_keys(self::LEGACY) as $name) {
            putenv($name);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_reads_the_environment_variables_of_grkamil_laravel_telegram_logging(): void
    {
        foreach (self::LEGACY as $name => $value) {
            putenv("{$name}={$value}");
        }

        $config = ChannelConfig::fromArrays(require __DIR__.'/../../config/telegram-logger.php', []);

        $this->assertSame('1:legacy', $config->token);
        $this->assertSame('-100500', $config->chatId);
        $this->assertSame('9', $config->threadId);
        $this->assertSame(3.0, $config->timeout);
        $this->assertSame('proxy.local:3128', $config->proxy);
        $this->assertSame('laravel-telegram-logging::minimal', $config->template);
    }

    #[Test]
    public function camel_case_and_snake_case_overrides_are_equivalent(): void
    {
        $a = ChannelConfig::resolve(['chatId' => '1', 'threadId' => 2, 'parseMode' => 'markdownv2']);
        $b = ChannelConfig::resolve(['chat_id' => '1', 'message_thread_id' => '2', 'parse_mode' => 'MarkdownV2']);

        $this->assertEquals($a, $b);
    }

    #[Test]
    public function empty_overrides_inherit_defaults(): void
    {
        $config = ChannelConfig::resolve(['chat_id' => '', 'thread_id' => null]);

        $this->assertSame('-1001234567890', $config->chatId);
        $this->assertNull($config->threadId);
    }
}
