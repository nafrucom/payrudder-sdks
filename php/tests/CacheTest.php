<?php

declare(strict_types=1);

namespace Payrudder\Tests;

use ArrayObject;
use Payrudder\Cache\CacheStore;
use Payrudder\Cache\MemoryStore;
use Payrudder\Cache\PayrudderCache;
use Payrudder\Cache\RedisStore;
use Payrudder\Client;
use Payrudder\Tests\Support\FakeTransport;
use Payrudder\Tests\Support\TestCase;
use Payrudder\Transport\HttpRequest;
use Payrudder\Transport\HttpResponse;
use Payrudder\Webhook\WebhookEvent;
use PHPUnit\Framework\Attributes\TestWith;
use Predis\Client as Predis;
use Redis;
use RuntimeException;
use Throwable;

final class CacheTest extends TestCase
{
    private string $used = '8';

    /** @var list<string> */
    private array $calls = [];

    private function api(string $apiKey = 'prk_test_abcdefgh_secret'): Client
    {
        $this->transport->handle(function (HttpRequest $r): HttpResponse {
            $path = (string) parse_url($r->url, PHP_URL_PATH);
            $query = (string) parse_url($r->url, PHP_URL_QUERY);
            $this->calls[] = $r->method . ' ' . $path . ($query !== '' ? '?' . $query : '');
            $ent = ['customer_external_id' => 'cus', 'meter_code' => 'api', 'used' => $this->used, 'limit' => '10', 'unlimited' => false, 'allowed' => true, 'mode' => 'hard'];

            return FakeTransport::json(200, match ($path) {
                '/v1/customers/cus/entitlements/api' => $ent,
                '/v1/customers/cus/entitlements' => ['items' => [$ent]],
                '/v1/plans' => ['plans' => [['code' => 'pro', 'latest_version' => 2], ['code' => 'draft', 'latest_version' => 0]]],
                '/v1/plans/pro/versions/2' => ['plan_code' => 'pro', 'version' => 2, 'prices' => []],
                '/v1/credit-packs' => ['packs' => []],
                '/v1/campaigns' => ['campaigns' => []],
                '/v1/events' => ['results' => [['status' => 'accepted']]],
                default => [],
            });
        });

        return new Client(apiKey: $apiKey, baseUrl: 'https://api.example.test', transport: $this->transport);
    }

    /** @return array<string,mixed> */
    private static function usage(string $quantity): array
    {
        return ['customer_external_id' => 'cus', 'meter_code' => 'api', 'quantity' => $quantity, 'event_time' => '2026-11-20T00:00:00Z', 'idempotency_key' => 'k'];
    }

    private function calls(string $call): int
    {
        return \count(array_filter($this->calls, static fn(string $c): bool => $c === $call));
    }

    /** @param array<string,mixed> $data */
    private static function event(string $type, array $data): WebhookEvent
    {
        return new WebhookEvent('ev', $type, '', '', $data);
    }

    public function testHitsAndCatalog(): void
    {
        $cache = new PayrudderCache($this->api());
        for ($i = 0; $i < 2; $i++) {
            self::assertSame('8', $cache->entitlement('cus', 'api')->used);
            $cache->entitlements('cus');
            $catalog = $cache->catalog();
            self::assertCount(1, $catalog['plans']);
            self::assertSame('pro', $catalog['plans'][0]['version']->planCode);
        }
        self::assertSame([
            'GET /v1/customers/cus/entitlements/api',
            'GET /v1/customers/cus/entitlements',
            'GET /v1/plans',
            'GET /v1/plans/pro/versions/2',
            'GET /v1/credit-packs?active=true',
            'GET /v1/campaigns?status=running',
        ], $this->calls);
    }

    public function testWebhooksInvalidateCustomerAndCatalog(): void
    {
        $cache = new PayrudderCache($this->api());
        $cache->entitlement('cus', 'api');
        $cache->catalog();
        $this->used = '9';
        self::assertTrue($cache->handleWebhook(self::event('subscription.changed', ['subscription' => ['customer_external_id' => 'cus']])));
        self::assertSame('9', $cache->entitlement('cus', 'api')->used);
        self::assertTrue($cache->handleWebhook(self::event('entitlement.updated', ['customer_external_id' => 'cus', 'meter_code' => ''])));
        self::assertTrue($cache->handleWebhook(self::event('invoice.paid', ['customer_external_id' => 'cus'])));
        self::assertFalse($cache->handleWebhook(self::event('customer.blocked', [])));
        $cache->catalog();
        self::assertSame(1, $this->calls('GET /v1/plans'));
        self::assertTrue($cache->handleWebhook(self::event('campaign.ended', [])));
        $cache->catalog();
        self::assertSame(2, $this->calls('GET /v1/plans'));
    }

    public function testTestAndLiveApart(): void
    {
        $store = new MemoryStore();
        $test = new PayrudderCache($this->api(), $store);
        $live = new PayrudderCache($this->api('prk_live_abcdefgh_secret'), $store);
        $test->entitlement('cus', 'api');
        $test->invalidateCustomer('cus');
        $live->entitlement('cus', 'api');
        $live->entitlement('cus', 'api');
        self::assertSame(2, $this->calls('GET /v1/customers/cus/entitlements/api'));
        self::assertSame('live', (new Client(apiKey: 'prk_live_x', transport: $this->transport))->environment());
    }

    public function testRecordUsageCrossesHardLimit(): void
    {
        $cache = new PayrudderCache($this->api());
        $cache->entitlement('cus', 'api');
        $cache->entitlements('cus');
        $cache->recordUsage(self::usage('1.5'));
        $s = $cache->entitlement('cus', 'api');
        self::assertSame(['9.5', true, false], [$s->used, $s->allowed, $s->overLimit]);
        $cache->recordUsage(self::usage('0.5'));
        $s = $cache->entitlement('cus', 'api');
        $all = $cache->entitlements('cus');
        self::assertSame(['10', false, '10', false], [$s->used, $s->allowed, $all[0]->used, $all[0]->allowed]);
        self::assertSame(1, $this->calls('GET /v1/customers/cus/entitlements/api'));
        self::assertSame(1, $this->calls('GET /v1/customers/cus/entitlements'));
    }

    public function testDropsAnEntryItCannotUpdate(): void
    {
        $store = new MemoryStore();
        $cache = new PayrudderCache($this->api(), $store);
        $cache->entitlement('cus', 'api');
        $gen = $store->get('payrudder:test:gen:c:cus');
        $store->set("payrudder:test:ent:cus:{$gen}:api", '{"used":3}', 60_000);
        $cache->recordUsage(self::usage('1'));
        $cache->entitlement('cus', 'api');
        self::assertSame(2, $this->calls('GET /v1/customers/cus/entitlements/api'));
    }

    public function testStoreFailureReadsThrough(): void
    {
        $broken = new class implements CacheStore {
            public function get(string $key): ?string
            {
                throw new RuntimeException('down');
            }

            public function set(string $key, string $value, int $ttlMs): void
            {
                throw new RuntimeException('down');
            }

            public function delete(string $key): void
            {
                throw new RuntimeException('down');
            }
        };
        $errors = [];
        $cache = new PayrudderCache($this->api(), $broken, onError: static function (Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        self::assertSame('8', $cache->entitlement('cus', 'api')->used);
        $cache->recordUsage(self::usage('1'));
        $cache->invalidateCatalog();
        self::assertGreaterThanOrEqual(3, \count($errors));
    }

    public function testMemoryStoreExpiresAndEvicts(): void
    {
        $now = 0.0;
        $store = new MemoryStore(2, static function () use (&$now): float {
            return $now;
        });
        $store->set('a', '1', 1000);
        $store->set('b', '2', 60_000);
        $store->get('a');
        $store->set('c', '3', 60_000);
        self::assertNull($store->get('b'));
        $now = 2.0;
        self::assertNull($store->get('a'));
        self::assertSame('3', $store->get('c'));
    }

    public function testRedisStoreSpeaksPredisAndPhpredis(): void
    {
        $seen = new ArrayObject();
        $phpredis = new class ($seen) {
            /** @param ArrayObject<int, mixed> $seen */
            public function __construct(private ArrayObject $seen) {}

            public function get(string $k): string|false
            {
                return $k === 'k' ? 'v' : false;
            }

            public function set(mixed ...$a): bool
            {
                $this->seen[] = $a;

                return true;
            }

            public function del(string $k): int
            {
                $this->seen[] = ['del', $k];

                return 1;
            }
        };
        $predis = new class ($seen) {
            /** @param ArrayObject<int, mixed> $seen */
            public function __construct(private ArrayObject $seen) {}

            public function executeCommand(mixed $command): mixed
            {
                return null;
            }

            /** @param array<mixed> $a */
            public function __call(string $name, array $a): mixed
            {
                $this->seen[] = [$name, ...$a];

                return null;
            }
        };
        $a = new RedisStore($phpredis);
        $a->set('k', 'v', 500);
        $a->delete('k');
        self::assertSame('v', $a->get('k'));
        self::assertNull($a->get('x'));
        $b = new RedisStore($predis);
        $b->set('k', 'v', 500);
        self::assertNull($b->get('k'));
        self::assertSame([
            ['k', 'v', ['px' => 500]],
            ['del', 'k'],
            ['set', 'k', 'v', 'PX', 500],
            ['get', 'k'],
        ], $seen->getArrayCopy());
    }

    /**
     * RedisStore against a real Redis. Skipped unless PAYRUDDER_TEST_REDIS_URL is set (redis://host:port), e.g.
     * docker run -d --rm -p 6390:6379 redis:7-alpine && PAYRUDDER_TEST_REDIS_URL=redis://localhost:6390 composer test.
     * phpredis runs only where the extension is loaded.
     */
    #[TestWith(['predis'])]
    #[TestWith(['phpredis'])]
    public function testRedisStoreOnARealRedis(string $with): void
    {
        $url = getenv('PAYRUDDER_TEST_REDIS_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('PAYRUDDER_TEST_REDIS_URL is not set');
        }
        if ($with === 'phpredis') {
            if (!\extension_loaded('redis')) {
                self::markTestSkipped('the redis extension is not loaded');
            }
            $client = new Redis();
            $client->connect((string) parse_url($url, PHP_URL_HOST), (int) (parse_url($url, PHP_URL_PORT) ?? 6379));
        } else {
            $client = new Predis($url);
        }
        $store = new RedisStore($client);
        $key = 'payrudder-sdk-test:php:' . $with . ':' . bin2hex(random_bytes(8));

        self::assertNull($store->get($key));
        $store->set($key, 'v1', 60_000);
        self::assertSame('v1', $store->get($key));
        $store->delete($key);
        self::assertNull($store->get($key));
        $store->set($key, 'short', 150);
        self::assertSame('short', $store->get($key));
        usleep(400_000);
        self::assertNull($store->get($key), 'the entry outlived its TTL');

        // Two instances share the store: one reads the other's entry, and a webhook to either invalidates both.
        $fail = static function (Throwable $e): void {
            throw $e;
        };
        $api = $this->api();
        $one = new PayrudderCache($api, $store, $key . ':', onError: $fail);
        $two = new PayrudderCache($api, $store, $key . ':', onError: $fail);
        self::assertSame('8', $one->entitlement('cus', 'api')->used);
        self::assertSame('8', $two->entitlement('cus', 'api')->used);
        self::assertSame(1, $this->calls('GET /v1/customers/cus/entitlements/api'));
        $this->used = '9';
        self::assertTrue($two->handleWebhook(self::event('entitlement.updated', ['customer_external_id' => 'cus'])));
        self::assertSame('9', $one->entitlement('cus', 'api')->used);
        self::assertSame(2, $this->calls('GET /v1/customers/cus/entitlements/api'));
    }
}
