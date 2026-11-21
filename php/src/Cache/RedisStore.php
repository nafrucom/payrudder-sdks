<?php

declare(strict_types=1);

namespace Payrudder\Cache;

/**
 * Wraps the Redis client you already have, Predis or phpredis (`\Redis`); the SDK gains no
 * dependency. Predis is told apart by its `executeCommand` method: it takes `set($k, $v, 'PX', $ms)`,
 * phpredis takes `set($k, $v, ['px' => $ms])`.
 */
final class RedisStore implements CacheStore
{
    private readonly bool $predis;

    public function __construct(private readonly object $client)
    {
        $this->predis = method_exists($client, 'executeCommand');
    }

    public function get(string $key): ?string
    {
        $value = $this->client->get($key); // @phpstan-ignore method.notFound

        return \is_string($value) ? $value : null; // phpredis answers false for a missing key, Predis null
    }

    public function set(string $key, string $value, int $ttlMs): void
    {
        if ($this->predis) {
            $this->client->set($key, $value, 'PX', $ttlMs); // @phpstan-ignore method.notFound
        } else {
            $this->client->set($key, $value, ['px' => $ttlMs]); // @phpstan-ignore method.notFound
        }
    }

    public function delete(string $key): void
    {
        $this->client->del($key); // @phpstan-ignore method.notFound
    }
}
