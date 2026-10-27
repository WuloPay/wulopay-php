<?php

namespace WuloPay\Tests;

use PHPUnit\Framework\TestCase;
use WuloPay\Exception\SignatureVerificationException;
use WuloPay\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function sign(string $body, int $t, string $secret = self::SECRET): string
    {
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    public function testMatchesTheSignatureWuloPaySends(): void
    {
        // Produced by WuloPay's own webhook signer (backend WebhookSecurity.Sign) for these inputs.
        Webhook::verifySignature('{"id":"evt_1"}',
            't=1761580800,v1=4c2ff7297f164f83fa634d9f076ed86ea9641c694f6d2478350198fa6a4dce44', 'whsec_test', 0);
        self::assertTrue(true);
    }

    public function testReturnsTheEvent(): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'status' => 'paid']]]);
        $event = Webhook::constructEvent($body, $this->sign($body, time()), self::SECRET);
        self::assertSame('cs_1', $event['data']['object']['id']);
    }

    public function testAcceptsAnyMatchingV1(): void
    {
        $body = '{"id":"evt_1","type":"webhook.test"}';
        $t = time();
        $header = 't=' . $t . ',v1=' . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::SECRET);
        self::assertSame('evt_1', Webhook::constructEvent($body, $header, self::SECRET)['id']);
    }

    public function testRejectsATamperedBody(): void
    {
        $body = '{"id":"evt_1","type":"checkout.session.completed"}';
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent(str_replace('evt_1', 'evt_2', $body), $this->sign($body, time()), self::SECRET);
    }

    public function testRejectsTheWrongSecret(): void
    {
        $body = '{"id":"evt_1","type":"x"}';
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($body, $this->sign($body, time(), 'whsec_other'), self::SECRET);
    }

    public function testRejectsAStaleTimestamp(): void
    {
        $body = '{"id":"evt_1","type":"x"}';
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($body, $this->sign($body, time() - 600), self::SECRET);
    }

    public function testRejectsAMissingHeaderAndAnEmptySecret(): void
    {
        foreach ([['', self::SECRET], ['t=1,v1=abc', '']] as [$header, $secret]) {
            try {
                Webhook::constructEvent('{}', $header, $secret);
                self::fail('expected a SignatureVerificationException');
            } catch (SignatureVerificationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }
}
