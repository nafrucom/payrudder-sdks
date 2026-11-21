<?php

declare(strict_types=1);

namespace Payrudder\Tests;

use Payrudder\Client;
use Payrudder\Enum\CustomerAccessReason;
use Payrudder\Model\AccessNotice;
use Payrudder\Model\EntitlementStatus;
use Payrudder\Tests\Support\FakeTransport;
use Payrudder\Tests\Support\TestCase;
use Payrudder\Transport\HttpRequest;
use Payrudder\Transport\HttpResponse;
use Payrudder\Webhook\WebhookEvent;

final class ConnectAccessTest extends TestCase
{
    /** @var list<HttpRequest> */
    private array $requests = [];

    /** @param array<string,mixed>|null $cache */
    private function api(?array $cache = null): Client
    {
        $this->transport->handle(function (HttpRequest $r): HttpResponse {
            $this->requests[] = $r;
            $path = (string) parse_url($r->url, PHP_URL_PATH);

            return FakeTransport::json(200, match (true) {
                str_ends_with($path, '/session') => ['url' => 'https://x/c/tok', 'expires_at' => '2026-11-22T00:00:00Z', 'customer' => ['external_id' => 'u1'], 'created' => true, 'billing_owner' => true],
                $path === '/v1/events' => ['results' => [['status' => 'accepted']]],
                default => [
                    'customer_external_id' => 'u1', 'allowed' => true, 'reason' => 'ok',
                    'subscription' => ['id' => 's1', 'state' => 'SUBSCRIPTION_STATE_ACTIVE', 'plan_code' => 'pro', 'trial_ends_at' => null, 'cancel_at_period_end' => false],
                    'meters' => [['customer_external_id' => 'u1', 'meter_code' => 'api', 'used' => '9', 'limit' => '10', 'unlimited' => false, 'allowed' => true, 'mode' => 'hard', 'over_limit' => false, 'suspended' => false]],
                    'notices' => [['code' => 'limit_near', 'severity' => 'warning', 'meter_code' => 'api', 'at' => null]],
                ],
            });
        });

        return new Client(apiKey: 'prk_test_abcdefgh_secret', baseUrl: 'https://api.example.test', transport: $this->transport, maxRetries: 0, cache: $cache);
    }

    private function accesses(): int
    {
        return \count(array_filter($this->requests, static fn(HttpRequest $r): bool => str_contains($r->url, '/access')));
    }

    public function testConnectRequest(): void
    {
        $out = $this->api()->connect(['id' => 'u1', 'name' => 'Acme', 'plan' => 'pro', 'member' => ['id' => 'm1', 'name' => 'Olga']]);
        $r = $this->requests[0];
        self::assertSame('PUT', $r->method);
        self::assertSame('https://api.example.test/v1/customers/u1/session', $r->url);
        self::assertSame(['name' => 'Acme', 'plan_code' => 'pro', 'member' => ['id' => 'm1', 'name' => 'Olga']], json_decode((string) $r->body, true));
        self::assertSame('https://x/c/tok', $out->url);
    }

    public function testAccessNoCache(): void
    {
        $p = $this->api();
        $p->access('u1', ['a', 'b']);
        $p->access('u1', ['a', 'b']);
        self::assertSame(2, $this->accesses());
        self::assertStringEndsWith('/v1/customers/u1/access?meters=a%2Cb', $this->requests[0]->url);
    }

    public function testAccessCached(): void
    {
        $p = $this->api([]);
        $p->access('u1', 'api');
        self::assertTrue($p->access('u1', 'api')->allowed);
        self::assertSame(1, $this->accesses());
    }

    public function testAccessMeterOrder(): void
    {
        $p = $this->api([]);
        $p->access('u1', ['a', 'b']);
        $p->access('u1', ['b', 'a']);
        self::assertSame(1, $this->accesses());
    }

    public function testAccessInvalidatedByInvoicePaid(): void
    {
        $p = $this->api([]);
        $p->access('u1', 'api');
        self::assertTrue($p->handleWebhook(new WebhookEvent('ev', 'invoice.paid', '', '', ['customer_external_id' => 'u1'])));
        $p->access('u1', 'api');
        self::assertSame(2, $this->accesses());
    }

    public function testRecordUsageFlipsAccess(): void
    {
        $p = $this->api([]);
        $p->access('u1', 'api');
        $p->recordUsage(['customer_external_id' => 'u1', 'meter_code' => 'api', 'quantity' => '1', 'event_time' => '2026-11-21T00:00:00Z', 'idempotency_key' => 'k']);
        $a = $p->access('u1', 'api');
        self::assertFalse($a->allowed);
        self::assertSame(CustomerAccessReason::LimitReached, $a->reason);
        $meter = ($a->meters ?? [])[0] ?? null;
        self::assertInstanceOf(EntitlementStatus::class, $meter);
        self::assertSame('10', $meter->used);
        self::assertSame(1, $this->accesses());
        $notices = $a->notices ?? [];
        self::assertCount(1, $notices);
        self::assertInstanceOf(AccessNotice::class, $notices[0]);
        self::assertSame('api', $notices[0]->meterCode);
    }
}
