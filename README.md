# Laravel Telegram Logger

Send Laravel logs and exception alerts to a Telegram chat, group or **forum topic** through a regular Monolog channel.

- one bot, many channels: every log channel can point to its own chat and topic (`message_thread_id`)
- HTML or MarkdownV2 with correct escaping, Blade templates
- long messages are split at the 4096 limit without breaking markup
- throttling of identical errors, `429 retry_after` handling, optional queue
- never throws and never blocks a request for longer than `timeout`
- `php artisan telegram-logger:test` to check the setup
- no dependencies besides `illuminate/*` and `monolog/monolog`

| Package | PHP | Laravel | Monolog |
|---------|-----|---------|---------|
| 1.x | 8.3 – 8.5 | 10, 11, 12, 13 | 3 |

> Laravel 10 and 11 are end-of-life. Composer refuses to install them by default because of unpatched security advisories; the package still works there.

## Installation

```bash
composer require xden/laravel-telegram-logger
```

Add the bot token and chat id to `.env`:

```dotenv
TELEGRAM_LOGGER_BOT_TOKEN=123456789:AAH...
TELEGRAM_LOGGER_CHAT_ID=-1001234567890
```

Add a channel to `config/logging.php`:

```php
'channels' => [
    // ...
    'telegram' => [
        'driver' => 'telegram',
        'level' => 'error',
    ],
],
```

Check it:

```bash
php artisan telegram-logger:test --channel=telegram
```

Optionally publish the config and the views:

```bash
php artisan vendor:publish --tag=telegram-logger-config
php artisan vendor:publish --tag=telegram-logger-views
```

## Getting the token, `chat_id` and `message_thread_id`

1. Open [@BotFather](https://t.me/BotFather), send `/newbot` and copy the token.
2. Add the bot to your group (or send `/start` to it in a private chat). For forum topics the group must have *Topics* enabled.
3. Send any message in the chat / topic, then open
   `https://api.telegram.org/bot<TOKEN>/getUpdates`:
   - `message.chat.id` is the `chat_id` (supergroups start with `-100`);
   - `message.message_thread_id` is the topic id.

   If the list is empty, the bot has *privacy mode* on: mention the bot in the message or disable privacy via BotFather → `/setprivacy`.
4. Alternatively, copy the link of a message inside a topic: `https://t.me/c/1234567890/12/345` means
   `chat_id = -1001234567890` and `thread_id = 12`.

## Configuring channels

Every option of `config/telegram-logger.php` can be overridden per channel, anything not set on the channel comes from the package config. Three equivalent styles are supported.

### 1. `telegram` driver (recommended)

```php
'telegram' => [
    'driver' => 'telegram',
    'level' => 'error',
],

'telegram_payments' => [
    'driver' => 'telegram',
    'thread_id' => env('TELEGRAM_LOG_PAYMENTS_THREAD_ID'),
    'level' => 'warning',
],

'telegram_ops' => [
    'driver' => 'telegram',
    'token' => env('TELEGRAM_OPS_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_OPS_CHAT_ID'),
    'parse_mode' => 'MarkdownV2',
    'template' => 'telegram-logger::minimal',
    'throttle' => 300,
    'queue' => ['connection' => 'redis', 'queue' => 'alerts'],
    'level' => 'critical',
],
```

### 2. Monolog handler

```php
'telegram_payments' => [
    'driver' => 'monolog',
    'handler' => Xden\TelegramLogger\TelegramHandler::class,
    'with' => [
        'token' => env('TELEGRAM_LOG_TOKEN'),
        'chatId' => env('TELEGRAM_LOG_CHAT_ID'),
        'threadId' => env('TELEGRAM_LOG_PAYMENTS_THREAD_ID'),
        'level' => 'warning',
        // optional: parseMode, throttle, queue, timeout, bubble,
        // and 'config' => [...] for any other option
    ],
],
```

### 3. `custom` driver (as in grkamil/laravel-telegram-logging)

```php
'telegram' => [
    'driver' => 'custom',
    'via' => Xden\TelegramLogger\TelegramLoggerFactory::class,
    'chat_id' => env('TELEGRAM_COMPANY_CHAT_ID'),
    'level' => 'debug',
],
```

### Usage

```php
use Illuminate\Support\Facades\Log;

Log::channel('telegram_payments')->warning('Refund is stuck', ['order' => $order->id]);

Log::channel('telegram')->error('Import failed', ['exception' => $e]);
```

Or add the channel to the stack (`LOG_STACK=single,telegram` on Laravel 11+, `'channels' => ['single', 'telegram']` in the `stack` channel on Laravel 10).

## Exceptions

Choose **one** of the options, otherwise every exception arrives twice.

**A. Log stack.** Laravel reports exceptions to the default channel, so adding `telegram` to the stack is enough:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=single,telegram
```

**B. Explicit report callback**, when Telegram should get exceptions only (not every `Log::error()`), or a dedicated topic.

Laravel 11+, `bootstrap/app.php`:

```php
use Illuminate\Support\Facades\Log;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (Throwable $e) {
        Log::channel('telegram')->error($e->getMessage(), ['exception' => $e]);
    });
})
```

Laravel 10, `app/Exceptions/Handler.php`:

```php
public function register(): void
{
    $this->reportable(function (Throwable $e) {
        Log::channel('telegram')->error($e->getMessage(), ['exception' => $e]);
    });
}
```

Exceptions listed in `$dontReport` / `dontReport()` never reach any log channel. Use the `ignore` option to skip additional classes only for Telegram.

## Options

`config/telegram-logger.php`, all of them can also be set on a channel:

| Key | Env | Default | |
|-----|-----|---------|---|
| `enabled` | `TELEGRAM_LOGGER_ENABLED` | `true` | master switch, set `false` in the `.env` of local/staging |
| `token` | `TELEGRAM_LOGGER_BOT_TOKEN` | | |
| `chat_id` | `TELEGRAM_LOGGER_CHAT_ID` | | |
| `thread_id` | `TELEGRAM_LOGGER_THREAD_ID` | | forum topic |
| `parse_mode` | `TELEGRAM_LOGGER_PARSE_MODE` | `HTML` | `HTML` or `MarkdownV2` |
| `template` | `TELEGRAM_LOGGER_TEMPLATE` | `telegram-logger::standard` | `false` for the built-in format |
| `include_trace_lines` | | `5` | `0` hides the trace |
| `include_request` | | `true` | method, URL, IP, user id (HTTP requests only) |
| `include_context` | | `true` | context and extra as JSON |
| `max_message_length` | | `4096` | |
| `max_messages` | | `3` | the rest of a long record is cut |
| `silent_levels` | | `debug, info, notice` | sent with `disable_notification` |
| `disable_web_page_preview` | | `true` | |
| `ignore` | | `[]` | exception classes not to send |
| `throttle` | `TELEGRAM_LOGGER_THROTTLE` | `0` | seconds, `0` = off |
| `cache_store` | `TELEGRAM_LOGGER_CACHE_STORE` | default | store used for throttling |
| `queue` | `TELEGRAM_LOGGER_QUEUE` | `null` | see below |
| `timeout` | `TELEGRAM_LOGGER_TIMEOUT` | `5.5` | seconds per log record |
| `proxy` | `TELEGRAM_LOGGER_PROXY` | | `host:port`, `http://…`, `socks5://…` |
| `api_host` | `TELEGRAM_LOGGER_API_HOST` | `https://api.telegram.org` | local Bot API server |
| `options` | | `[]` | extra [sendMessage](https://core.telegram.org/bots/api#sendmessage) parameters |

### Throttling

```php
'throttle' => 300,
```

Identical events are sent at most once per 300 seconds. An event is identified by the exception class + file + line, or by level + message when there is no exception, and by the destination chat/topic. The cache is used through `Cache::add()`; if the cache is unavailable, throttling is skipped and the message is sent.

When Telegram answers `429 Too Many Requests`, synchronous sending pauses for `retry_after` seconds.

### Queue

```dotenv
TELEGRAM_LOGGER_QUEUE=true
TELEGRAM_LOGGER_QUEUE_CONNECTION=redis
TELEGRAM_LOGGER_QUEUE_NAME=alerts
```

or per channel `'queue' => ['connection' => 'redis', 'queue' => 'alerts']` (`true` uses the default connection, `false` forces synchronous sending for a channel).

Records are sent by `Xden\TelegramLogger\Jobs\SendTelegramMessageJob`. Its payload is encrypted because it contains the bot token. Network errors, `5xx` and `429` are retried (up to 5 attempts, honouring `retry_after`). The job never throws, so a broken Telegram setup cannot create a failing job → log → job loop. If dispatching fails (e.g. Redis is down), the message is sent synchronously.

### Templates

Built-in: `telegram-logger::standard` (header, message, exception, trace, request, context) and `telegram-logger::minimal`. Publish them with `--tag=telegram-logger-views` or point `template` to your own view.

Available variables: `$fmt`, `$appName`, `$appEnv`, `$level`, `$levelName`, `$emoji`, `$message`, `$datetime`, `$exception` (`class`, `message`, `location`, `trace`), `$context`, `$extra`, `$request`, `$channel`, `$record`.

Always print values through `$fmt` with `{!! !!}`. It escapes for the active parse mode, so one template works for HTML and MarkdownV2:

```blade
{!! $emoji !!} {!! $fmt->bold($appName) !!} {!! $fmt->code($levelName) !!}
{!! $fmt->text($message) !!}
@if ($exception)
{!! $fmt->pre($exception['class'].' '.$exception['location']) !!}
@endif
```

Helpers: `text`, `bold`, `italic`, `code`, `pre($text, $language)`, `link($text, $url)`. If a template fails to render, the built-in format is used and the error goes to `error_log`.

## Manual alerts

```php
use Xden\TelegramLogger\Facades\TelegramLog;

TelegramLog::send('Deploy finished');                      // default chat
TelegramLog::send('Payout batch is late', threadId: 12);   // a topic
TelegramLog::send('Nightly import OK', channel: 'telegram_ops', silent: true);
TelegramLog::send('<b>Raw</b> HTML', escape: false);       // your own markup
```

`send()` returns `false` instead of throwing. `TelegramLog::escape($text)` escapes text for the configured parse mode.

## Test command

```bash
php artisan telegram-logger:test
php artisan telegram-logger:test --channel=telegram_payments --level=critical --message="Hello"
```

It prints the effective settings (the token is masked), sends a test record with a sample exception synchronously, bypassing queue and throttling, and explains Telegram errors.

## Reliability

- The handler never throws. Its own failures (network, API errors, broken templates, missing config) are written to PHP's `error_log`, never to the Laravel log, so there is no recursion.
- A log call made while a message is being sent is ignored by the handler.
- All requests of one log record share the `timeout` budget; remaining parts are dropped when it runs out.
- Bot tokens are masked in every error message.

## Troubleshooting

| Telegram error | Cause |
|----------------|-------|
| `401 Unauthorized` / `404 Not Found` | wrong or revoked token |
| `400 Bad Request: chat not found` | wrong `chat_id`, the bot is not in the chat, or never got `/start` in a private chat. Supergroup ids start with `-100` |
| `400 Bad Request: message thread not found` | wrong `thread_id` or topics are disabled |
| `400 Bad Request: TOPIC_CLOSED` | the topic is closed |
| `400 Bad Request: group chat was upgraded to a supergroup chat` | use the new id (`migrate_to_chat_id`, the command prints it) |
| `403 Forbidden: bot was blocked by the user / kicked` | add the bot again |
| `400 Bad Request: not enough rights to send text messages` | give the bot permission to post |
| `400 Bad Request: can't parse entities` | invalid markup in a custom template, print values via `$fmt` |
| `429 Too Many Requests` | enable `throttle` and/or the queue |
| `Could not reach Telegram API` | network/firewall, set `TELEGRAM_LOGGER_PROXY` or `api_host` |

Nothing arrives and no error in the command? Check `TELEGRAM_LOGGER_ENABLED` and the channel `level`, and look at the PHP error log (`[telegram-logger]` lines).

## Migrating from grkamil/laravel-telegram-logging

`TELEGRAM_LOGGER_BOT_TOKEN`, `TELEGRAM_LOGGER_CHAT_ID`, `TELEGRAM_LOGGER_MESSAGE_THREAD_ID`, `TELEGRAM_LOGGER_TIMEOUT`, `TELEGRAM_LOGGER_TEMPLATE`, `TELEGRAM_LOGGER_PROXY` and `TELEGRAM_LOGGER_API_HOST` are read as they are. The template names `laravel-telegram-logging::standard|minimal` still resolve. Replace the channel:

```php
// before
'telegram' => ['driver' => 'custom', 'via' => Logger\TelegramLogger::class, 'level' => 'debug'],
// after
'telegram' => ['driver' => 'telegram', 'level' => 'debug'],
```

Custom templates of the old package get `$level_name`, `$appName`, `$appEnv`, `$datetime` and `$formatted`, but should be rewritten with `$fmt` for proper escaping. Lumen is not supported.

## Development

```bash
composer test      # PHPUnit
composer analyse   # PHPStan level 8 (Larastan)
composer format    # Pint
```

## License

MIT, see [LICENSE](LICENSE).
