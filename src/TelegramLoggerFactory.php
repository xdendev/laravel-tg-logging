<?php

declare(strict_types=1);

namespace Xden\TelegramLogger;

use Monolog\Logger;

/**
 * Creates the logger for `'driver' => 'telegram'` and
 * `'driver' => 'custom', 'via' => TelegramLoggerFactory::class` channels.
 */
final class TelegramLoggerFactory
{
    /**
     * @param  array<array-key, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $name = is_string($config['name'] ?? null) ? $config['name'] : 'telegram';

        return new Logger($name, [TelegramHandler::fromChannel($config)]);
    }
}
