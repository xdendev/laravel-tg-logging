<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Throwable;

/**
 * Cache based de-duplication. Any cache failure disables throttling
 * instead of breaking logging.
 */
class Throttler
{
    private const PREFIX = 'telegram-logger:';

    public function __construct(private readonly CacheFactory $cache) {}

    /**
     * True when the event identified by $key may be sent now.
     */
    public function attempt(string $key, int $seconds, ?string $store = null): bool
    {
        if ($seconds <= 0) {
            return true;
        }

        try {
            return $this->cache->store($store)->add(self::PREFIX.'throttle:'.sha1($key), time(), $seconds);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Pause sending for $key (bot token) after a 429 response.
     */
    public function cooldown(string $key, int $seconds, ?string $store = null): void
    {
        if ($seconds <= 0) {
            return;
        }

        try {
            $this->cache->store($store)->put(self::PREFIX.'cooldown:'.sha1($key), time() + $seconds, $seconds);
        } catch (Throwable) {
            // throttling is best effort
        }
    }

    public function coolingDown(string $key, ?string $store = null): bool
    {
        try {
            return $this->cache->store($store)->has(self::PREFIX.'cooldown:'.sha1($key));
        } catch (Throwable) {
            return false;
        }
    }
}
