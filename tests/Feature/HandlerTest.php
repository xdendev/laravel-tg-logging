<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Xden\TelegramLogger\Jobs\SendTelegramMessageJob;
use Xden\TelegramLogger\Tests\TestCase;

final class HandlerTest extends TestCase
{
    #[Test]
    public function it_sends_a_message_to_the_configured_chat(): void
    {
        $this->fakeTelegram();

        Log::channel('telegram')->error('Payment failed');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => self::isSendMessage($request)
            && $request['chat_id'] === '-1001234567890'
            && $request['parse_mode'] === 'HTML'
            && $request['disable_notification'] === false
            && ! isset($request['message_thread_id'])
            && str_contains($request['text'], 'Payment failed')
            && str_contains($request['text'], '<b>Shop</b>'));
    }

    #[Test]
    public function the_telegram_driver_sends_to_the_channel_topic(): void
    {
        $this->fakeTelegram();

        Log::channel('tg_payments')->warning('Refund stuck');

        Http::assertSent(fn (Request $request): bool => $request['message_thread_id'] === 7);
    }

    #[Test]
    public function the_monolog_handler_style_overrides_defaults(): void
    {
        $this->fakeTelegram();

        Log::channel('tg_handler')->error('Boom');

        Http::assertSent(fn (Request $request): bool => self::isSendMessage($request)
            && $request['chat_id'] === '-1009999'
            && $request['message_thread_id'] === 12);
    }

    #[Test]
    public function the_custom_via_style_overrides_token_and_chat(): void
    {
        $this->fakeTelegram();

        Log::channel('tg_custom')->info('Hello');

        Http::assertSent(fn (Request $request): bool => self::isSendMessage($request, '987654321:BBHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw')
            && $request['chat_id'] === '555');
    }

    #[Test]
    public function channel_options_override_package_config(): void
    {
        $this->fakeTelegram();
        config()->set('logging.channels.tg_md', [
            'driver' => 'telegram',
            'parse_mode' => 'MarkdownV2',
            'chat_id' => '-100777',
            'thread_id' => 3,
            'template' => 'telegram-logger::minimal',
            'disable_web_page_preview' => false,
        ]);

        Log::channel('tg_md')->error('Order #15 failed (retry).');

        Http::assertSent(fn (Request $request): bool => $request['parse_mode'] === 'MarkdownV2'
            && $request['chat_id'] === '-100777'
            && $request['message_thread_id'] === 3
            && ! isset($request['link_preview_options'])
            && str_contains($request['text'], 'Order \#15 failed \(retry\)\.'));
    }

    #[Test]
    public function several_channels_with_different_topics_work_side_by_side(): void
    {
        $this->fakeTelegram();

        Log::channel('tg_payments')->error('one');
        Log::channel('tg_handler')->error('two');
        Log::channel('telegram')->error('three');

        $threads = array_map(fn (array $payload) => $payload['message_thread_id'] ?? null, $this->sentPayloads());
        $this->assertSame([7, 12, null], $threads);
    }

    #[Test]
    public function it_escapes_html(): void
    {
        $this->fakeTelegram();

        Log::channel('telegram')->error('<script>alert(1)</script> & co', ['html' => '<b>']);

        $text = $this->lastText();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; co', $text);
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringContainsString('&lt;b&gt;', $text);
    }

    #[Test]
    public function it_formats_exceptions_with_relative_location_and_trace(): void
    {
        $this->fakeTelegram();

        Log::channel('telegram')->error('Something broke', ['exception' => new LogicException('Inner reason'), 'order' => 15]);

        $text = $this->lastText();
        $this->assertStringContainsString('<b>LogicException</b>', $text);
        $this->assertStringContainsString('Inner reason', $text);
        $this->assertStringContainsString('HandlerTest.php:', $text);
        $this->assertStringContainsString('#0 ', $text);
        $this->assertStringContainsString('&quot;order&quot;: 15', str_replace('"', '&quot;', $text));
        $this->assertStringContainsString('<pre><code class="language-json">', $text);
    }

    #[Test]
    public function it_respects_the_channel_level(): void
    {
        $this->fakeTelegram();

        Log::channel('tg_payments')->info('not important');
        Log::channel('tg_handler')->warning('below error');

        Http::assertNothingSent();
    }

    #[Test]
    public function low_levels_are_sent_silently(): void
    {
        $this->fakeTelegram();

        Log::channel('telegram')->info('fyi');
        Log::channel('telegram')->critical('wake up');

        $silent = array_column($this->sentPayloads(), 'disable_notification');
        $this->assertSame([true, false], $silent);
    }

    #[Test]
    public function long_messages_are_split_within_the_limit(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.max_message_length', 500);
        config()->set('telegram-logger.max_messages', 10);

        Log::channel('telegram')->error(str_repeat('Lorem ipsum <dolor> sit amet. ', 60));

        $payloads = $this->sentPayloads();
        $this->assertGreaterThan(1, count($payloads));

        foreach ($payloads as $payload) {
            $this->assertLessThanOrEqual(500, mb_strlen($payload['text']));
            $this->assertSame(substr_count($payload['text'], '<b>'), substr_count($payload['text'], '</b>'));
            $this->assertDoesNotMatchRegularExpression('/&[a-z]*$/', $payload['text']);
        }
    }

    #[Test]
    public function messages_beyond_max_messages_are_truncated(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.max_message_length', 300);
        config()->set('telegram-logger.max_messages', 2);

        Log::channel('telegram')->error(str_repeat('x ', 2000));

        $payloads = $this->sentPayloads();
        $this->assertCount(2, $payloads);
        $this->assertStringEndsWith('<i>… truncated</i>', $payloads[1]['text']);
        $this->assertLessThanOrEqual(300, mb_strlen($payloads[1]['text']));
    }

    #[Test]
    public function identical_errors_are_throttled(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.throttle', 60);

        for ($i = 0; $i < 3; $i++) {
            Log::channel('telegram')->error('Same', ['exception' => $this->exceptionAt()]);
        }

        Log::channel('telegram')->error('Other', ['exception' => new LogicException('other place')]);
        // the same error in another topic is throttled separately
        Log::channel('tg_payments')->error('Same', ['exception' => $this->exceptionAt()]);

        Http::assertSentCount(3);
    }

    #[Test]
    public function throttling_is_skipped_when_the_cache_is_broken(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.throttle', 60);
        config()->set('telegram-logger.cache_store', 'missing-store');

        Log::channel('telegram')->error('one');
        Log::channel('telegram')->error('one');

        Http::assertSentCount(2);
    }

    #[Test]
    public function network_errors_never_escape_the_handler(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'));

        Log::channel('telegram')->error('Boom');

        $this->assertCount(1, $this->failures);
        $this->assertStringContainsString('Could not reach Telegram API', $this->failures[0]);
        $this->assertStringNotContainsString(self::TOKEN, $this->failures[0]);
        $this->assertStringNotContainsString('AAHdqTcvCH1', $this->failures[0]);
    }

    #[Test]
    public function error_responses_never_escape_the_handler(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)
            ->push('Bad gateway', 502);

        Log::channel('telegram')->error('one');
        Log::channel('telegram')->error('two');

        $this->assertCount(2, $this->failures);
        $this->assertStringContainsString('chat not found', $this->failures[0]);
        $this->assertStringContainsString('502', $this->failures[1]);
    }

    #[Test]
    public function rate_limit_pauses_sending_for_retry_after(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 30', 'parameters' => ['retry_after' => 30]], 429)
            ->push(['ok' => true, 'result' => []]);

        Log::channel('telegram')->error('one');
        Log::channel('telegram')->error('two');

        Http::assertSentCount(1);
        $this->assertStringContainsString('Rate limited', $this->failures[1]);
    }

    #[Test]
    public function the_job_is_queued_when_the_queue_is_enabled(): void
    {
        Queue::fake();
        $this->fakeTelegram();
        config()->set('telegram-logger.queue', ['connection' => 'redis', 'queue' => 'alerts']);

        Log::channel('tg_payments')->error('Queued');

        Http::assertNothingSent();
        Queue::assertPushedOn('alerts', SendTelegramMessageJob::class, fn (SendTelegramMessageJob $job): bool => $job->connection === 'redis'
            && $job->payload['message_thread_id'] === 7
            && str_contains($job->payload['text'], 'Queued'));
    }

    #[Test]
    public function a_channel_can_opt_out_of_the_queue(): void
    {
        Queue::fake();
        $this->fakeTelegram();
        config()->set('telegram-logger.queue', ['connection' => null, 'queue' => null]);
        config()->set('logging.channels.tg_sync', ['driver' => 'telegram', 'queue' => false]);

        Log::channel('tg_sync')->error('now');

        Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    #[Test]
    public function ignored_exceptions_are_not_sent(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.ignore', [InvalidArgumentException::class]);

        Log::channel('telegram')->error('ignored', ['exception' => new \DomainException('subclass of LogicException, not ignored')]);
        Log::channel('telegram')->error('ignored', ['exception' => new \UnexpectedValueException('not ignored')]);
        Log::channel('telegram')->error('ignored', ['exception' => new InvalidArgumentException('ignored')]);

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_is_disabled_outside_of_configured_environments(): void
    {
        $this->fakeTelegram();

        config()->set('telegram-logger.environments', 'production,staging');
        Log::channel('telegram')->error('no');

        config()->set('telegram-logger.environments', ['testing']);
        Log::channel('telegram')->error('yes');

        config()->set('telegram-logger.enabled', false);
        Log::channel('telegram')->error('no');

        Http::assertSentCount(1);
    }

    #[Test]
    public function missing_credentials_are_reported_not_thrown(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.token', null);

        Log::channel('telegram')->error('Boom');

        Http::assertNothingSent();
        $this->assertStringContainsString('no token or chat_id', $this->failures[0]);
    }

    #[Test]
    public function it_includes_request_details(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.include_request', true);
        (fn () => $this->isRunningInConsole = false)->call($this->app);
        $this->app->instance('request', HttpRequest::create('https://shop.test/checkout?step=2', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']));

        Log::channel('telegram')->error('Boom');

        $text = $this->lastText();
        $this->assertStringContainsString('<code>POST</code> https://shop.test/checkout?step=2', $text);
        $this->assertStringContainsString('IP: <code>10.0.0.1</code>', $text);
    }

    #[Test]
    public function a_broken_template_falls_back_to_the_builtin_format(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.template', 'does-not::exist');

        Log::channel('telegram')->error('Still delivered');

        $this->assertStringContainsString('Still delivered', $this->lastText());
        $this->assertStringContainsString('Failed to render template', $this->failures[0]);
    }

    #[Test]
    public function legacy_template_names_still_resolve(): void
    {
        $this->fakeTelegram();
        config()->set('telegram-logger.template', 'laravel-telegram-logging::minimal');

        Log::channel('telegram')->error('Legacy');

        $this->assertStringContainsString('Legacy', $this->lastText());
        $this->assertSame([], $this->failures);
    }

    #[Test]
    public function logging_from_inside_the_sender_does_not_recurse(): void
    {
        Http::fake(function () {
            Log::channel('telegram')->error('nested');

            return Http::response(['ok' => true, 'result' => []]);
        });

        Log::channel('telegram')->error('outer');

        Http::assertSentCount(1);
    }

    private function exceptionAt(): RuntimeException
    {
        return new RuntimeException('same place');
    }
}
