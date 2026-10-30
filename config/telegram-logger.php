<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Disable sending to Telegram completely without touching log channels,
    | e.g. TELEGRAM_LOGGER_ENABLED=false in the .env of local environments.
    |
    */

    'enabled' => env('TELEGRAM_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Bot and destination
    |--------------------------------------------------------------------------
    |
    | Defaults for every Telegram log channel. Each channel in
    | config/logging.php may override any of these keys.
    |
    | thread_id is the forum topic id (message_thread_id) inside a supergroup.
    |
    */

    'token' => env('TELEGRAM_LOGGER_BOT_TOKEN'),

    'chat_id' => env('TELEGRAM_LOGGER_CHAT_ID'),

    'thread_id' => env('TELEGRAM_LOGGER_THREAD_ID', env('TELEGRAM_LOGGER_MESSAGE_THREAD_ID')),

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    |
    | parse_mode: "HTML" or "MarkdownV2". All user supplied text is escaped
    | for the selected mode.
    |
    | template: Blade view used to render a message. Built-in views are
    | "telegram-logger::standard" and "telegram-logger::minimal". Set to
    | false to use the plain built-in formatter.
    |
    */

    'parse_mode' => env('TELEGRAM_LOGGER_PARSE_MODE', 'HTML'),

    'template' => env('TELEGRAM_LOGGER_TEMPLATE', 'telegram-logger::standard'),

    'include_trace_lines' => 5,

    'include_request' => true,

    'include_context' => true,

    // Telegram limit is 4096 characters per message.
    'max_message_length' => 4096,

    // Long messages are split into up to this many messages, the rest is cut.
    'max_messages' => 3,

    // Levels delivered with disable_notification (no sound).
    'silent_levels' => ['debug', 'info', 'notice'],

    'disable_web_page_preview' => true,

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    |
    | Exception classes (and their subclasses) that are never sent.
    |
    */

    'ignore' => [
        // Illuminate\Validation\ValidationException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttling
    |--------------------------------------------------------------------------
    |
    | Identical events (same exception class + file + line, or the same
    | message) are sent at most once per this many seconds. 0 disables it.
    |
    */

    'throttle' => env('TELEGRAM_LOGGER_THROTTLE', 0),

    'cache_store' => env('TELEGRAM_LOGGER_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | null sends synchronously. An array dispatches SendTelegramMessageJob to
    | the given connection / queue (null values mean the app defaults).
    |
    */

    'queue' => env('TELEGRAM_LOGGER_QUEUE', false) ? [
        'connection' => env('TELEGRAM_LOGGER_QUEUE_CONNECTION'),
        'queue' => env('TELEGRAM_LOGGER_QUEUE_NAME'),
    ] : null,

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    */

    // Total time budget in seconds for sending one log record synchronously.
    'timeout' => env('TELEGRAM_LOGGER_TIMEOUT', 5.5),

    // e.g. "socks5://127.0.0.1:1080" or "proxy.example.com:3128"
    'proxy' => env('TELEGRAM_LOGGER_PROXY'),

    'api_host' => env('TELEGRAM_LOGGER_API_HOST', 'https://api.telegram.org'),

    // Extra sendMessage parameters: https://core.telegram.org/bots/api#sendmessage
    'options' => [
        // 'protect_content' => true,
    ],

];
