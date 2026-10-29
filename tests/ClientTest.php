<?php

namespace WuloPay\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WuloPay\Client;
use WuloPay\Exception\AuthenticationException;
use WuloPay\Exception\ConflictException;
use WuloPay\Exception\InvalidRequestException;
use WuloPay\Exception\NotFoundException;
use WuloPay\Exception\PermissionException;
use WuloPay\Exception\RateLimitException;
use WuloPay\TokenCacheInterface;

final class ClientTest extends TestCase
{
    private function client(FakeHttpClient $http, ?TokenCacheInterface $cache = null): Client
    {
        return new Client('wk_test', 'secret', ['httpClient' => $http, 'tokenCache' => $cache]);
    }

    private function token(FakeHttpClient $http, string $token = 'tok1'): FakeHttpClient
    {
        return $http->queue(200, ['authToken' => $token, 'expiresIn' => 3600, 'refreshToken' => null]);
    }

    public function testCreatesASessionWithTokenAndIdempotencyKey(): void
    {
        $http = $this->token(new FakeHttpClient())->queue(201, ['id' => 'cs_abc', 'status' => 'open', 'url' => 'https://pay-sandbox.wulopay.com/c/cs_abc']);
        $session = $this->client($http)->checkoutSessions->create([
            'amount' => '149.999', 'currency' => 'ZAR', 'reference' => '1042', 'returnUrl' => 'https://shop.example/return',
        ], 'order-1042');

        self::assertSame('cs_abc', $session['id']);
        self::assertSame('https://sandbox.wulopay.com/api/v1/Authorization/Token/ApiToken', $http->requests[0]['url']);
        self::assertSame(['key' => 'wk_test', 'secret' => 'secret'], json_decode($http->requests[0]['body'], true));
        $create = $http->requests[1];
        self::assertSame('POST', $create['method']);
        self::assertSame('https://sandbox.wulopay.com/api/v1/Checkout/Sessions', $create['url']);
        self::assertSame('Bearer tok1', $create['headers']['Authorization']);
        self::assertSame('order-1042', $create['headers']['Idempotency-Key']);
        self::assertSame(150.0, json_decode($create['body'], true)['amount']);
    }

    public function testReusesTheTokenAcrossCalls(): void
    {
        $http = $this->token(new FakeHttpClient())->queue(200, ['id' => 'cs_1'])->queue(200, ['id' => 'cs_2']);
        $client = $this->client($http);
        $client->checkoutSessions->retrieve('cs_1');
        $client->checkoutSessions->retrieve('cs_2');
        self::assertCount(3, $http->requests, 'one token request, two API calls');
    }

    public function testRefreshesAnExpiredTokenOnce(): void
    {
        $http = $this->token(new FakeHttpClient(), 'old')
            ->queue(401, ['error' => 'expired'])
            ->queue(200, ['authToken' => 'new', 'expiresIn' => 3600])
            ->queue(200, ['id' => 'cs_1', 'status' => 'paid']);
        $session = $this->client($http)->checkoutSessions->retrieve('cs_1');
        self::assertSame('paid', $session['status']);
        self::assertSame('Bearer new', $http->requests[3]['headers']['Authorization']);
    }

    public function testUsesASharedTokenCache(): void
    {
        $cache = new class implements TokenCacheInterface {
            public $items = [];
            public function get(string $key): ?string { return $this->items[$key] ?? null; }
            public function set(string $key, string $token, int $ttlSeconds): void { $this->items[$key] = $token; }
            public function delete(string $key): void { unset($this->items[$key]); }
        };
        $http = $this->token(new FakeHttpClient(), 'shared')->queue(200, ['id' => 'cs_1'])->queue(200, ['id' => 'cs_1']);
        $this->client($http, $cache)->checkoutSessions->retrieve('cs_1');
        $this->client($http, $cache)->checkoutSessions->retrieve('cs_1');   // a second PHP request
        self::assertCount(3, $http->requests, 'the second client took the token from the cache');
        self::assertContains('shared', $cache->items);
    }

    public function testRefundSendsAmountAndReason(): void
    {
        $http = $this->token(new FakeHttpClient())->queue(200, ['sessionId' => 'cs_1', 'refundedThisTime' => 20.0, 'totalRefunded' => 20.0, 'remainingRefundable' => 80.0]);
        $refund = $this->client($http)->checkoutSessions->refund('cs_1', 20, 'Damaged item');
        self::assertEquals(80.0, $refund['remainingRefundable']);
        self::assertSame('https://sandbox.wulopay.com/api/v1/Checkout/Sessions/cs_1/Refunds', $http->requests[1]['url']);
        self::assertSame(['amount' => 20.0, 'reason' => 'Damaged item'], json_decode($http->requests[1]['body'], true));
    }

    public function testAFullRefundSendsAnEmptyObject(): void
    {
        $http = $this->token(new FakeHttpClient())->queue(200, ['sessionId' => 'cs_1']);
        $this->client($http)->checkoutSessions->refund('cs_1');
        self::assertSame('{}', $http->requests[1]['body']);
    }

    public function testReadsValidationProblemMessages(): void
    {
        $http = $this->token(new FakeHttpClient())->queue(400, ['title' => 'One or more validation errors occurred.', 'errors' => ['amount' => ['The amount field is required.']]]);
        $this->expectExceptionMessage('The amount field is required.');
        $this->client($http)->checkoutSessions->create(['currency' => 'ZAR']);
    }

    public function testEncodesTheSessionIdInPaths(): void
    {
        $http = $this->token(new FakeHttpClient())->queue(200, ['id' => 'x']);
        $this->client($http)->checkoutSessions->cancel('cs_1/../x');
        self::assertSame('https://sandbox.wulopay.com/api/v1/Checkout/Sessions/cs_1%2F..%2Fx/Cancel', $http->requests[1]['url']);
    }

    #[DataProvider('errors')]
    public function testMapsErrors(int $status, string $class): void
    {
        $http = $this->token(new FakeHttpClient())->queue($status, ['error' => 'Something specific.'], ['retry-after' => '30']);
        try {
            $this->client($http)->checkoutSessions->retrieve('cs_1');
            self::fail('expected an exception');
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame('Something specific.', $e->getMessage());
            if ($e instanceof RateLimitException) {
                self::assertSame(30, $e->getRetryAfter());
            }
        }
    }

    public static function errors(): array
    {
        return [[400, InvalidRequestException::class], [403, PermissionException::class], [404, NotFoundException::class],
                [409, ConflictException::class], [429, RateLimitException::class]];
    }

    public function testWrongCredentialsRaiseAuthentication(): void
    {
        $http = (new FakeHttpClient())->queue(401, ['error' => 'Invalid API key or secret.']);
        $this->expectException(AuthenticationException::class);
        $this->client($http)->checkoutSessions->retrieve('cs_1');
    }

    public function testRefusesAPlainHttpBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('wk_test', 'secret', ['baseUrl' => 'http://example.com']);
    }

    public function testCreatesAPlanAndASubscriptionSignupWithoutAmount(): void
    {
        $http = $this->token(new FakeHttpClient())->queue(201, ['id' => 'plan_1'])->queue(201, ['id' => 'cs_1', 'mode' => 'subscription']);
        $client = $this->client($http);
        $plan = $client->plans->create(['name' => 'Gold', 'amount' => '49.004', 'interval' => 'month', 'trialDays' => 14]);
        self::assertSame('https://sandbox.wulopay.com/api/v1/Billing/Plans', $http->requests[1]['url']);
        self::assertSame(49.0, json_decode($http->requests[1]['body'], true)['amount']);
        $client->checkoutSessions->create([
            'mode' => 'subscription', 'subscription' => ['planId' => $plan['id'], 'billingMode' => 'wulopay'],
            'reference' => 'SUB-1', 'customer' => ['email' => 'a@example.com'], 'returnUrl' => 'https://shop.example/r',
        ]);
        $body = json_decode($http->requests[2]['body'], true);
        self::assertArrayNotHasKey('amount', $body);
        self::assertSame(['planId' => 'plan_1', 'billingMode' => 'wulopay'], $body['subscription']);
    }

    public function testChargesWithTheIdempotencyKeyAndReturnsADeclinedCharge(): void
    {
        $http = $this->token(new FakeHttpClient())->queue(200, ['id' => 'ch_2', 'status' => 'failed', 'retryAllowed' => false]);
        $charge = $this->client($http)->subscriptions->charge('sub_1', 'renewal-1043', 55);
        $call = $http->requests[1];
        self::assertSame('https://sandbox.wulopay.com/api/v1/Billing/Subscriptions/sub_1/Charges', $call['url']);
        self::assertSame('renewal-1043', $call['headers']['Idempotency-Key']);
        self::assertSame(['amount' => 55.0], json_decode($call['body'], true));
        self::assertFalse($charge['retryAllowed']);
    }

    public function testRefusesAChargeWithoutAnIdempotencyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeHttpClient())->subscriptions->charge('sub_1', '');
    }

    public function testCancelsAtPeriodEndListsByStatusAndCreatesLinks(): void
    {
        $http = $this->token(new FakeHttpClient())
            ->queue(200, ['id' => 'sub_1', 'cancelAtPeriodEnd' => true])
            ->queue(200, ['object' => 'list', 'data' => []])
            ->queue(201, ['url' => 'https://pay-sandbox.wulopay.com/c/cs_9'])
            ->queue(200, ['url' => 'https://pay-sandbox.wulopay.com/m/t']);
        $subs = $this->client($http)->subscriptions;
        $subs->cancel('sub_1', true);
        self::assertSame(['atPeriodEnd' => true], json_decode($http->requests[1]['body'], true));
        $subs->all('past_due', 10);
        self::assertSame('https://sandbox.wulopay.com/api/v1/Billing/Subscriptions?status=past_due&limit=10', $http->requests[2]['url']);
        $subs->createCardUpdateSession('sub_1', 'https://shop.example/account');
        self::assertSame('https://sandbox.wulopay.com/api/v1/Billing/Subscriptions/sub_1/CardUpdateSession', $http->requests[3]['url']);
        self::assertStringContainsString('/m/', $subs->createManageLink('sub_1')['url']);
    }
}
