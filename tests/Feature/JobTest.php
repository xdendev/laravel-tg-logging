<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Xden\TelegramLogger\Jobs\SendTelegramMessageJob;
use Xden\TelegramLogger\Tests\TestCase;

final class JobTest extends TestCase
{
    #[Test]
    public function it_sends_the_payload(): void
    {
        $this->fakeTelegram();

        $this->app->call([$this->job(), 'handle']);

        Http::assertSent(fn (Request $request): bool => self::isSendMessage($request) && $request['text'] === 'hi');
    }

    #[Test]
    public function it_is_released_with_retry_after_on_429(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 17]], 429)]);
        $job = $this->job();
        $queueJob = $this->queueJob(attempts: 1);
        $job->setJob($queueJob);

        $this->app->call([$job, 'handle']);

        $this->assertSame([17], $queueJob->delays);
        $this->assertSame([], $this->failures);
    }

    #[Test]
    public function it_uses_backoff_on_server_errors_and_gives_up_without_throwing(): void
    {
        Http::fake(['*' => Http::response('oops', 500)]);

        $job = $this->job();
        $job->setJob($first = $this->queueJob(attempts: 2));
        $this->app->call([$job, 'handle']);
        $this->assertSame([30], $first->delays);

        $job = $this->job();
        $job->setJob($last = $this->queueJob(attempts: 5));
        $this->app->call([$job, 'handle']);
        $this->assertSame([], $last->delays);
        $this->assertStringContainsString('after 5 attempt(s)', $this->failures[0]);
    }

    #[Test]
    public function client_errors_are_not_retried(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)]);
        $job = $this->job();
        $job->setJob($queueJob = $this->queueJob(attempts: 1));

        $this->app->call([$job, 'handle']);

        $this->assertSame([], $queueJob->delays);
        $this->assertCount(1, $this->failures);
    }

    #[Test]
    public function the_payload_is_encrypted(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, $this->job());
    }

    private function job(): SendTelegramMessageJob
    {
        return new SendTelegramMessageJob(self::TOKEN, ['chat_id' => '1', 'text' => 'hi']);
    }

    private function queueJob(int $attempts): Job
    {
        return new class($attempts) extends \Illuminate\Queue\Jobs\Job implements Job
        {
            /** @var list<int> */
            public array $delays = [];

            public function __construct(private readonly int $attemptCount) {}

            public function release($delay = 0): void
            {
                parent::release($delay);
                $this->delays[] = $delay;
            }

            public function attempts(): int
            {
                return $this->attemptCount;
            }

            public function getJobId(): string
            {
                return '1';
            }

            public function getRawBody(): string
            {
                return '{}';
            }
        };
    }
}
