<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Formatting;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use Xden\TelegramLogger\Config\ChannelConfig;
use Xden\TelegramLogger\Support\FailureReporter;

/**
 * Renders a log record into Telegram markup using a Blade template.
 *
 * Template variables: $fmt (Markup), $appName, $appEnv, $level, $levelName,
 * $emoji, $message, $datetime, $exception, $context, $extra, $request,
 * $channel, $record. For templates written for grkamil/laravel-telegram-logging
 * $level_name and $formatted are available as well.
 */
class MessageFormatter
{
    public function __construct(
        private readonly Container $app,
        private readonly FailureReporter $reporter,
    ) {}

    public function format(LogRecord $record, ChannelConfig $config): string
    {
        $data = $this->data($record, $config);

        $text = $config->template !== null ? $this->render($config->template, $data) : null;

        return $this->tidy($text ?? $this->fallback($data));
    }

    public static function emoji(Level $level): string
    {
        return match ($level) {
            Level::Debug => '🐞',
            Level::Info => 'ℹ️',
            Level::Notice => '📘',
            Level::Warning => '⚠️',
            Level::Error => '🔴',
            Level::Critical => '🔥',
            Level::Alert => '🚨',
            Level::Emergency => '💥',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(LogRecord $record, ChannelConfig $config): array
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $normalizer = new ContextNormalizer;
        $contextJson = $config->includeContext ? $normalizer->encode($context) : null;
        $extraJson = $config->includeContext ? $normalizer->encode($record->extra) : null;
        $budget = $config->maxMessageLength * $config->maxMessages;

        return [
            'fmt' => new Markup($config->parseMode),
            'record' => $record,
            'channel' => $config->channel,
            'appName' => $this->appName(),
            'appEnv' => $this->environment(),
            'level' => $record->level,
            'levelName' => $record->level->getName(),
            'emoji' => self::emoji($record->level),
            'message' => $this->limit($record->message, $budget),
            'datetime' => $record->datetime,
            'exception' => $exception instanceof Throwable ? $this->exception($exception, $record->message, $config) : null,
            'context' => $contextJson !== null ? $this->limit($contextJson, $budget) : null,
            'extra' => $extraJson !== null ? $this->limit($extraJson, $budget) : null,
            'request' => $config->includeRequest ? $this->request() : null,
            // grkamil/laravel-telegram-logging compatibility
            'level_name' => $record->level->getName(),
            'formatted' => trim($record->message.' '.($normalizer->encode($context, pretty: false) ?? '')),
        ];
    }

    /**
     * @return array{class: class-string, message: ?string, location: string, trace: list<string>}
     */
    protected function exception(Throwable $exception, string $logMessage, ChannelConfig $config): array
    {
        $message = $exception->getMessage();
        $trace = [];

        foreach ($exception->getTrace() as $frame) {
            if (count($trace) >= $config->includeTraceLines) {
                break;
            }

            if (! isset($frame['file'])) {
                continue;
            }

            $call = isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::').$frame['function'] : $frame['function'];
            $trace[] = sprintf('#%d %s:%d %s()', count($trace), $this->relativePath($frame['file']), $frame['line'] ?? 0, $call);
        }

        return [
            'class' => $exception::class,
            'message' => $message !== '' && $message !== $logMessage ? $message : null,
            'location' => $this->relativePath($exception->getFile()).':'.$exception->getLine(),
            'trace' => $trace,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    protected function request(): ?array
    {
        try {
            if ($this->app instanceof Application && $this->app->runningInConsole()) {
                return null;
            }

            if (! $this->app->bound('request')) {
                return null;
            }

            /** @var Request $request */
            $request = $this->app->make('request');

            return array_filter([
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => (string) $request->ip(),
                'user_id' => $this->userId(),
            ], static fn (?string $value): bool => $value !== null && $value !== '');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Only reads an already authenticated user, never triggers a lookup.
     */
    protected function userId(): ?string
    {
        try {
            if (! $this->app->bound('auth')) {
                return null;
            }

            $guard = $this->app->make('auth')->guard();

            if (! $guard->hasUser()) {
                return null;
            }

            $id = $guard->id();

            return is_scalar($id) ? (string) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function render(string $template, array $data): ?string
    {
        try {
            /** @var ViewFactory $view */
            $view = $this->app->make('view');

            return $view->make($template, $data)->render(); // @phpstan-ignore argument.type (user configured view name)
        } catch (Throwable $e) {
            $this->reporter->report("Failed to render template [{$template}], using the built-in format", $e);

            return null;
        }
    }

    /**
     * Template-free format, used when no template is configured or it fails.
     *
     * @param  array<string, mixed>  $data
     */
    protected function fallback(array $data): string
    {
        /** @var Markup $fmt */
        $fmt = $data['fmt'];
        /** @var array{class: string, message: ?string, location: string, trace: list<string>}|null $exception */
        $exception = $data['exception'];

        $lines = [
            $data['emoji'].' '.$fmt->bold((string) $data['appName']).' '.$fmt->code((string) $data['appEnv']).' '.$fmt->bold((string) $data['levelName']),
            $fmt->text((string) $data['message']),
        ];

        if ($exception !== null) {
            $lines[] = $fmt->code($exception['class'].' '.$exception['location']);
        }

        if (is_string($data['context'])) {
            $lines[] = $fmt->pre($data['context'], 'json');
        }

        return implode("\n", $lines);
    }

    protected function tidy(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    protected function relativePath(string $path): string
    {
        $base = $this->app instanceof Application ? $this->app->basePath() : '';

        if ($base !== '' && str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    private function limit(string $text, int $length): string
    {
        return mb_strlen($text, 'UTF-8') > $length ? mb_substr($text, 0, $length, 'UTF-8').'…' : $text;
    }

    private function appName(): string
    {
        $name = config('app.name');

        return is_string($name) && $name !== '' ? $name : 'Laravel';
    }

    private function environment(): string
    {
        return $this->app instanceof Application ? $this->app->environment() : (string) config('app.env', 'production');
    }
}
