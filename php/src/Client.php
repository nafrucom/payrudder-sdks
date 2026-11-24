<?php

declare(strict_types=1);

namespace Payrudder;

use Closure;
use Payrudder\Cache\PayrudderCache;
use Payrudder\Contract\RequestBody;
use Payrudder\Exception\ConfigurationException;
use Payrudder\Internal\Requester;
use Payrudder\Model\ConnectCustomerResponse;
use Payrudder\Model\CustomerAccess;
use Payrudder\Model\EventResult;
use Payrudder\Resource\Generated\ResourceHolder;
use Payrudder\Transport\CurlTransport;
use Payrudder\Transport\Psr18Transport;
use Payrudder\Transport\TransportInterface;
use Payrudder\Webhook\WebhookEvent;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Entry point of the SDK. One method per operation of the tenant API (`/v1/*`), grouped in
 * resource namespaces: `$client->customers->createCustomer(...)`.
 *
 * The HTTP transport is, in order: the `transport` given, a PSR-18 `httpClient` given (factories
 * are discovered when missing), ext-curl, a client found by php-http/discovery.
 */
final class Client extends ResourceHolder
{
    public const VERSION = '0.3.0';
    public const DEFAULT_BASE_URL = 'http://localhost:42800';

    private readonly string $environment;

    private readonly ?PayrudderCache $cache;

    /**
     * @param string|null $apiKey `prk_test_...` or `prk_live_...`; defaults to $PAYRUDDER_API_KEY
     * @param string|null $baseUrl defaults to $PAYRUDDER_BASE_URL, then http://localhost:42800
     * @param float $timeout seconds per attempt
     * @param int $maxRetries automatic retries after the first attempt (0 disables)
     * @param string|null $userAgent replaces the default `payrudder-php/<version>`
     * @param TransportInterface|null $transport replaces the whole HTTP layer (tests, custom stacks)
     * @param ClientInterface|null $httpClient any PSR-18 client
     * @param Closure(float):void|null $sleeper how to wait between retries (seconds); defaults to usleep
     * @param array<string,mixed>|null $cache PayrudderCache's named arguments (`store`, `accessTtlMs`, ...); with it, access(),
     *                                        recordUsage() and handleWebhook() go through the cache. `[]` is an in-memory cache.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?string $userAgent = null,
        ?TransportInterface $transport = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?Closure $sleeper = null,
        ?array $cache = null,
    ) {
        if (\PHP_INT_SIZE < 8) {
            throw new ConfigurationException('the Payrudder SDK needs a 64-bit PHP: money amounts are int64');
        }
        $apiKey = self::nonEmpty($apiKey) ?? self::nonEmpty(self::env('PAYRUDDER_API_KEY'));
        if ($apiKey === null) {
            throw new ConfigurationException('an API key is required: pass apiKey or set PAYRUDDER_API_KEY');
        }
        $baseUrl = self::nonEmpty($baseUrl) ?? self::nonEmpty(self::env('PAYRUDDER_BASE_URL')) ?? self::DEFAULT_BASE_URL;
        $baseUrl = rtrim($baseUrl, '/');
        if (preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            throw new ConfigurationException('baseUrl must start with http:// or https://');
        }
        if ($timeout <= 0) {
            throw new ConfigurationException('timeout must be above zero');
        }
        if ($maxRetries < 0) {
            throw new ConfigurationException('maxRetries must not be negative');
        }

        $this->environment = str_starts_with($apiKey, 'prk_test_') ? 'test' : 'live';
        $transport ??= self::defaultTransport($httpClient, $requestFactory, $streamFactory);

        parent::__construct(new Requester(
            $transport,
            $baseUrl,
            $apiKey,
            $timeout,
            $maxRetries,
            $userAgent ?? 'payrudder-php/' . self::VERSION,
            $sleeper ?? static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            },
        ));
        /** @phpstan-ignore argument.type (named arguments of PayrudderCache, checked when it is built) */
        $this->cache = $cache === null ? null : new PayrudderCache($this, ...$cache);
    }

    /**
     * Creates or updates the customer and returns the link to their customer page (`url` is null for a
     * member who does not manage payment).
     *
     * @param array{id: string, name: string, email?: string, locale?: string, plan?: string, member?: array{id: string, name?: string, email?: string}, expiresInDays?: int} $input
     */
    public function connect(array $input): ConnectCustomerResponse
    {
        $body = ['name' => $input['name']];
        foreach (['email' => 'email', 'locale' => 'locale', 'plan' => 'plan_code', 'member' => 'member', 'expiresInDays' => 'expires_in_days'] as $from => $to) {
            if (isset($input[$from])) {
                $body[$to] = $input[$from];
            }
        }

        return $this->customers->connectCustomer($input['id'], $body);
    }

    /**
     * Whether the customer may use the service now, by subscription, payment and the meters given.
     * Cached when the client has a cache.
     *
     * @param string|list<string> $meters
     */
    public function access(string $customer, string|array $meters = [], ?string $member = null): CustomerAccess
    {
        $list = \is_string($meters) ? [$meters] : $meters;
        if ($this->cache !== null) {
            return $this->cache->access($customer, $list, $member);
        }

        return $this->customers->getCustomerAccess($customer, $list === [] ? null : implode(',', $list), $member);
    }

    /**
     * Records one usage event; with a cache, counts it into the cached answers too.
     *
     * @param RequestBody|array<string,mixed> $event
     */
    public function recordUsage(RequestBody|array $event): EventResult
    {
        return $this->cache !== null ? $this->cache->recordUsage($event) : $this->events->ingest([$event])[0];
    }

    /** Invalidates cached answers for a verified webhook event; false without a cache. */
    public function handleWebhook(WebhookEvent $event): bool
    {
        return $this->cache !== null && $this->cache->handleWebhook($event);
    }

    /** The cache the `cache` argument asked for; null without it. */
    public function cache(): ?PayrudderCache
    {
        return $this->cache;
    }

    /** The API key's environment: "test" for a `prk_test_` key, "live" for every other key. */
    public function environment(): string
    {
        return $this->environment;
    }

    private static function defaultTransport(?ClientInterface $httpClient, ?RequestFactoryInterface $requestFactory, ?StreamFactoryInterface $streamFactory): TransportInterface
    {
        if ($httpClient !== null) {
            return Psr18Transport::discover($httpClient, $requestFactory, $streamFactory);
        }
        if (\extension_loaded('curl')) {
            return new CurlTransport();
        }

        return Psr18Transport::discover();
    }

    private static function nonEmpty(?string $v): ?string
    {
        return $v === null || trim($v) === '' ? null : trim($v);
    }

    private static function env(string $name): ?string
    {
        $v = getenv($name);

        return \is_string($v) ? $v : null;
    }
}
