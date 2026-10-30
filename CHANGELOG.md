# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `TelegramHandler` Monolog handler and the `telegram` log driver (`driver => custom` + `via` is supported too).
- Per-channel `token`, `chat_id`, `thread_id` (forum topics), `parse_mode`, `throttle`, `queue`, `timeout` and any other option.
- HTML and MarkdownV2 with full escaping; Blade templates `standard` and `minimal`.
- Splitting of long messages without breaking markup, `max_messages` limit.
- Throttling of identical events, `429 retry_after` cool-down, total time budget per record.
- Optional delivery through the queue (`SendTelegramMessageJob`, encrypted payload).
- `ignore` and `environments` filters, silent notifications for low levels.
- `telegram-logger:test` command with hints for common Telegram API errors.
- `TelegramLog` facade for manual alerts.
- Environment variables of `grkamil/laravel-telegram-logging` are read as defaults.
