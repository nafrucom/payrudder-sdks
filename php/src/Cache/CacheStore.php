<?php

declare(strict_types=1);

namespace Payrudder\Cache;

/**
 * Where PayrudderCache keeps its entries. MemoryStore and RedisStore ship with the SDK; anything
 * else (APCu, Memcached) fits by implementing these three methods.
 */
interface CacheStore
{
    /** The value, or null for a missing or expired key. */
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlMs): void;

    public function delete(string $key): void;
}
