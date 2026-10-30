<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Xden\TelegramLogger\Facades\TelegramLog;
use Xden\TelegramLogger\Tests\TestCase;

final class FacadeTest extends TestCase
{
    #[Test]
    public function it_sends_escaped_text_to_a_topic(): void
    {
        $this->fakeTelegram();

        $this->assertTrue(TelegramLog::send('Deploy <v2> done', threadId: 12));

        Http::assertSent(fn (Request $request): bool => $request['message_thread_id'] === 12
            && $request['text'] === 'Deploy &lt;v2&gt; done');
    }

    #[Test]
    public function it_can_use_a_log_channel_and_raw_markup(): void
    {
        $this->fakeTelegram();

        TelegramLog::send('<b>bold</b>', channel: 'tg_custom', escape: false, silent: true);

        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '555'
            && $request['text'] === '<b>bold</b>'
            && $request['disable_notification'] === true);
    }

    #[Test]
    public function it_returns_false_instead_of_throwing(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->assertFalse(TelegramLog::send('x'));
        $this->assertFalse(TelegramLog::send('x', channel: 'missing'));
    }

    #[Test]
    public function it_escapes_for_markdown(): void
    {
        $this->assertSame('a\_b\.c', TelegramLog::escape('a_b.c', 'MarkdownV2'));
    }
}
