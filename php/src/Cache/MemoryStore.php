<?php

declare(strict_types=1);

namespace Payrudder\Cache;

use Closure;

/**
 * A least-recently-used store in the memory of this PHP process. Under PHP-FPM that is one request,
 * so use RedisStore to share entries between requests and servers.
 */
final class MemoryStore implements CacheStore
{
    /** @var array<string, array{0: string, 1: float}> value and expiry, least recently used first */
    private array $entries = [];

    /** @var Closure(): float */
    private Closure $now;

    /**
     * @param (Closure(): float)|null $now the clock in seconds, for tests
     */
    public function __construct(private readonly int $maxEntries = 10_000, ?Closure $now = null)
    {
        $this->now = $now ?? static fn(): float => microtime(true);
    }

    public function get(string $key): ?string
    {
        if (!isset($this->entries[$key])) {
            return null;
        }
        $hit = $this->entries[$key];
        unset($this->entries[$key]);
        if ($hit[1] <= ($this->now)()) {
            return null;
        }
        $this->entries[$key] = $hit; // re-inserting makes it the most recently used

        return $hit[0];
    }

    public function set(string $key, string $value, int $ttlMs): void
    {
        unset($this->entries[$key]);
        $this->entries[$key] = [$value, ($this->now)() + $ttlMs / 1000];
        if (\count($this->entries) > $this->maxEntries) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }
}
