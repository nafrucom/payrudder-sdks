<?php

declare(strict_types=1);

namespace Payrudder\Cache;

use Closure;
use Payrudder\Client;
use Payrudder\Contract\RequestBody;
use Payrudder\Enum\EventResultStatus;
use Payrudder\Model\Campaign;
use Payrudder\Model\CreditPack;
use Payrudder\Model\EntitlementStatus;
use Payrudder\Model\EventResult;
use Payrudder\Model\Plan;
use Payrudder\Model\PlanVersion;
use Payrudder\Webhook\WebhookEvent;
use Throwable;

/**
 * Entitlement checks and the catalog answered from a store every instance of your app shares.
 *
 * Payrudder stays the source of truth: pass verified webhooks to handleWebhook() and a change
 * invalidates the cached answers at once; without it they are refreshed after the TTL.
 * Invalidation is by generation: each customer and the catalog have a generation key, entries are
 * stored under the generation they were read at, and invalidating writes a new random generation.
 * A store error never fails a call: it reads through to the API and goes to `onError`.
 */
final class PayrudderCache
{
    private const CUSTOMER_EVENTS = ['entitlement.updated', 'customer.blocked', 'customer.unblocked', 'credits.purchased'];
    private const CATALOG_EVENTS = ['plan.created', 'plan.version_published', 'campaign.started', 'campaign.ended'];

    private readonly CacheStore $store;
    private readonly string $base;
    private readonly int $genTtlMs;

    /** @var Closure(Throwable): void */
    private readonly Closure $onError;

    /**
     * @param string $prefix the API key's environment follows it, so test and live never share entries
     * @param (Closure(Throwable): void)|null $onError called with every store error
     */
    public function __construct(
        private readonly Client $client,
        ?CacheStore $store = null,
        string $prefix = 'payrudder:',
        private readonly int $entitlementTtlMs = 60_000,
        private readonly int $catalogTtlMs = 600_000,
        ?Closure $onError = null,
    ) {
        $this->store = $store ?? new MemoryStore();
        $this->base = $prefix . $client->environment() . ':';
        $this->genTtlMs = 2 * max($entitlementTtlMs, $catalogTtlMs);
        $this->onError = $onError ?? static function (Throwable $e): void {};
    }

    /** The customer's status on one meter. */
    public function entitlement(string $customer, string $meter): EntitlementStatus
    {
        $wire = $this->cached(
            $this->customerGenKey($customer),
            static fn(string $g): string => "ent:{$customer}:{$g}:{$meter}",
            $this->entitlementTtlMs,
            fn(): array => $this->client->customers->getEntitlement($customer, $meter)->toArray(),
        );

        return EntitlementStatus::fromArray(self::map($wire));
    }

    /**
     * The customer's status on every meter they have an entitlement on.
     *
     * @return list<EntitlementStatus>
     */
    public function entitlements(string $customer): array
    {
        $wire = $this->cached(
            $this->customerGenKey($customer),
            static fn(string $g): string => "ents:{$customer}:{$g}",
            $this->entitlementTtlMs,
            fn(): array => self::wireList($this->client->customers->listEntitlements($customer)->items),
        );

        return array_values(array_map(static fn(mixed $s): EntitlementStatus => EntitlementStatus::fromArray(self::map($s)), $wire));
    }

    /**
     * Plans with their current prices, active credit packs and running campaigns.
     *
     * @return array{plans: list<array{plan: Plan, version: PlanVersion}>, packs: list<CreditPack>, campaigns: list<Campaign>}
     */
    public function catalog(): array
    {
        $wire = $this->cached($this->base . 'gen:catalog', static fn(string $g): string => "catalog:{$g}", $this->catalogTtlMs, function (): array {
            $plans = [];
            foreach ($this->client->plans->listPlans()->plans ?? [] as $plan) {
                if (!$plan instanceof Plan || ($plan->latestVersion ?? 0) < 1 || $plan->code === null) {
                    continue; // nothing published: no prices to show
                }
                $version = $this->client->plans->getPlanVersion($plan->code, (string) $plan->latestVersion);
                $plans[] = ['plan' => $plan->toArray(), 'version' => $version->toArray()];
            }

            return [
                'plans' => $plans,
                'packs' => self::wireList($this->client->creditPacks->listCreditPacks(true)->packs),
                'campaigns' => self::wireList($this->client->campaigns->listCampaigns('running')->campaigns),
            ];
        });

        $list = static fn(mixed $v): array => \is_array($v) ? array_values($v) : [];

        return [
            'plans' => array_map(static fn(mixed $p): array => [
                'plan' => Plan::fromArray(self::map(self::map($p)['plan'] ?? [])),
                'version' => PlanVersion::fromArray(self::map(self::map($p)['version'] ?? [])),
            ], $list($wire['plans'] ?? null)),
            'packs' => array_map(static fn(mixed $p): CreditPack => CreditPack::fromArray(self::map($p)), $list($wire['packs'] ?? null)),
            'campaigns' => array_map(static fn(mixed $c): Campaign => Campaign::fromArray(self::map($c)), $list($wire['campaigns'] ?? null)),
        ];
    }

    /**
     * Records one usage event through the API, then counts it into the cached entitlement of that
     * customer and meter (`used += quantity`, `allowed` recomputed for a hard limit), so the next
     * check needs no request.
     *
     * @param RequestBody|array<string,mixed> $event an IncomingEvent or its array
     */
    public function recordUsage(RequestBody|array $event): EventResult
    {
        $result = $this->client->events->ingest([$event])[0];
        $status = $result->status instanceof EventResultStatus ? $result->status->value : $result->status;
        if ($status !== 'accepted') {
            return $result;
        }
        $wire = $event instanceof RequestBody ? $event->toArray() : $event;
        $str = static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '';
        $customer = $str($wire['customer_external_id'] ?? null);
        $meter = $str($wire['meter_code'] ?? null);
        $quantity = $str($wire['quantity'] ?? null);
        [$ok, $gen] = $this->read($this->customerGenKey($customer));
        if (!$ok || $gen === null || $gen === '') {
            return $result; // no generation: nothing was cached under it
        }

        $one = $this->base . "ent:{$customer}:{$gen}:{$meter}";
        [$ok, $raw] = $this->read($one);
        if ($ok && $raw !== null) {
            $s = self::decode($raw);
            if (\is_array($s) && self::countUsage($s, $quantity)) {
                $this->write($one, self::encode($s), $this->entitlementTtlMs);
            } else {
                $this->remove($one);
            }
        }

        $all = $this->base . "ents:{$customer}:{$gen}";
        [$ok, $raw] = $this->read($all);
        if ($ok && $raw !== null) {
            $items = self::decode($raw);
            if (!\is_array($items)) {
                $this->remove($all);

                return $result;
            }
            foreach ($items as $i => $s) {
                if (!\is_array($s) || ($s['meter_code'] ?? null) !== $meter) {
                    continue;
                }
                if (self::countUsage($s, $quantity)) {
                    $items[$i] = $s;
                    $this->write($all, self::encode($items), $this->entitlementTtlMs);
                } else {
                    $this->remove($all);
                }
                break;
            }
        }

        return $result;
    }

    /** Invalidates by the type of an already verified event (Webhook::constructEvent); says whether it did anything. */
    public function handleWebhook(WebhookEvent $event): bool
    {
        if (\in_array($event->type, self::CATALOG_EVENTS, true)) {
            $this->invalidateCatalog();

            return true;
        }
        if (!\in_array($event->type, self::CUSTOMER_EVENTS, true) && !str_starts_with($event->type, 'subscription.')) {
            return false;
        }
        $customer = $event->data['customer_external_id'] ?? null;
        if (!\is_string($customer) || $customer === '') {
            $sub = $event->data['subscription'] ?? null;
            $customer = \is_array($sub) ? ($sub['customer_external_id'] ?? null) : null;
        }
        if (!\is_string($customer) || $customer === '') {
            return false;
        }
        $this->invalidateCustomer($customer);

        return true;
    }

    /** The customer's next entitlement checks ask the API. */
    public function invalidateCustomer(string $customer): void
    {
        $this->write($this->customerGenKey($customer), self::token(), $this->genTtlMs);
    }

    /** The next catalog read asks the API. */
    public function invalidateCatalog(): void
    {
        $this->write($this->base . 'gen:catalog', self::token(), $this->genTtlMs);
    }

    private function customerGenKey(string $customer): string
    {
        return $this->base . "gen:c:{$customer}";
    }

    /**
     * A missing generation is created. It is not refreshed on read: re-setting the value read could
     * undo an invalidation written in between, and a generation that expires costs one miss (the new
     * one is random). Null: the store failed, so nothing is cached.
     */
    private function generation(string $key): ?string
    {
        [$ok, $gen] = $this->read($key);
        if (!$ok) {
            return null;
        }
        if ($gen !== null && $gen !== '') {
            return $gen;
        }
        $fresh = self::token();

        return $this->write($key, $fresh, $this->genTtlMs) ? $fresh : null;
    }

    /**
     * @param Closure(string): string $keyOf
     * @param Closure(): array<mixed> $load
     *
     * @return array<mixed>
     */
    private function cached(string $genKey, Closure $keyOf, int $ttlMs, Closure $load): array
    {
        $gen = $this->generation($genKey);
        if ($gen === null) {
            return $load();
        }
        $key = $this->base . $keyOf($gen);
        [$ok, $raw] = $this->read($key);
        if ($ok && $raw !== null) {
            $hit = self::decode($raw);
            if (\is_array($hit)) {
                return $hit;
            }
        }
        $value = $load();
        $this->write($key, self::encode($value), $ttlMs);

        return $value;
    }

    /** @return array{0: bool, 1: ?string} false when the store failed */
    private function read(string $key): array
    {
        try {
            return [true, $this->store->get($key)];
        } catch (Throwable $e) {
            ($this->onError)($e);

            return [false, null];
        }
    }

    private function write(string $key, string $value, int $ttlMs): bool
    {
        try {
            $this->store->set($key, $value, $ttlMs);

            return true;
        } catch (Throwable $e) {
            ($this->onError)($e);

            return false;
        }
    }

    private function remove(string $key): void
    {
        try {
            $this->store->delete($key);
        } catch (Throwable $e) {
            ($this->onError)($e);
        }
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(8));
    }

    private static function encode(mixed $v): string
    {
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function decode(string $raw): mixed
    {
        $v = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);

        return json_last_error() === JSON_ERROR_NONE ? $v : null;
    }

    /**
     * @param array<mixed>|null $models
     *
     * @return list<array<mixed>>
     */
    private static function wireList(?array $models): array
    {
        return array_values(array_map(static fn(mixed $m): array => $m instanceof RequestBody ? $m->toArray() : self::map($m), $models ?? []));
    }

    /** @return array<string,mixed> */
    private static function map(mixed $v): array
    {
        $out = [];
        if (\is_array($v)) {
            foreach ($v as $k => $x) {
                $out[(string) $k] = $x;
            }
        }

        return $out;
    }

    /**
     * Adds $quantity to `used` and recomputes `over_limit`, and `allowed` for a hard limit, in place.
     * False: the entry lacks what is needed (the caller then drops it).
     *
     * @param array<mixed> $s
     */
    private static function countUsage(array &$s, string $quantity): bool
    {
        $used = $s['used'] ?? null;
        if (!\is_string($used)) {
            return false;
        }
        $sum = Decimal::add($used, $quantity);
        if ($sum === null) {
            return false;
        }
        $s['used'] = $sum;
        $limit = $s['limit'] ?? null;
        if (($s['unlimited'] ?? false) === true || $limit === null) {
            return true;
        }
        $cmp = \is_string($limit) ? Decimal::compare($sum, $limit) : null;
        if ($cmp === null) {
            return false;
        }
        $s['over_limit'] = $cmp > 0;
        if (($s['mode'] ?? null) === 'hard') {
            $s['allowed'] = ($s['suspended'] ?? false) !== true && $cmp < 0;
        }

        return true;
    }
}
