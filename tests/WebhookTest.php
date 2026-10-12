<?php

declare(strict_types=1);

namespace Payshen\Tests;

use Payshen\Exception\SignatureVerificationException;
use Payshen\MemoryWebhookEventStore;
use Payshen\Webhook;
use PHPUnit\Framework\TestCase;

/** Signature checks against headers made the way Payshen makes them (src/lib/webhooks.ts). */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_php_sdk';
    private const NOW = 1_791_801_000;
    // Non-ASCII on purpose: the HMAC is over the raw bytes.
    private const BODY = '{"id":"evt_1","type":"payment.captured","created":"2026-10-12T10:30:00Z","data":{"payment_id":"pay_1","description":"Čaj za 5 €"}}';

    private static function header(int $t, string $body = self::BODY, string $secret = self::SECRET): string
    {
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    private static function refusal(callable $call): string
    {
        try {
            $call();
        } catch (SignatureVerificationException $e) {
            return $e->errorCode;
        }
        return 'accepted';
    }

    public function testTheRealSignatureVerifiesAndParses(): void
    {
        $event = Webhook::parseEvent(self::BODY, self::header(self::NOW), self::SECRET, now: self::NOW);
        $this->assertSame('evt_1', $event['id']);
        $this->assertSame('payment.captured', $event['type']);
        $this->assertSame('Čaj za 5 €', $event['data']['description']);
    }

    public function testEverythingElseIsRefusedWithItsReason(): void
    {
        $h = self::header(self::NOW);
        $cases = [
            'changed body' => [fn () => Webhook::verifySignature(self::BODY . ' ', $h, self::SECRET, now: self::NOW), 'invalid_signature'],
            'wrong secret' => [fn () => Webhook::verifySignature(self::BODY, $h, 'whsec_other', now: self::NOW), 'invalid_signature'],
            'six minutes old' => [fn () => Webhook::verifySignature(self::BODY, self::header(self::NOW - 360), self::SECRET, now: self::NOW), 'expired_signature'],
            'from the future' => [fn () => Webhook::verifySignature(self::BODY, self::header(self::NOW + 360), self::SECRET, now: self::NOW), 'expired_signature'],
            'no header' => [fn () => Webhook::verifySignature(self::BODY, null, self::SECRET, now: self::NOW), 'missing_signature'],
            'empty header' => [fn () => Webhook::verifySignature(self::BODY, '', self::SECRET, now: self::NOW), 'missing_signature'],
            'garbage' => [fn () => Webhook::verifySignature(self::BODY, 'garbage', self::SECRET, now: self::NOW), 'malformed_signature'],
            'no v1' => [fn () => Webhook::verifySignature(self::BODY, 't=' . self::NOW, self::SECRET, now: self::NOW), 'malformed_signature'],
            'timestamp not a number' => [fn () => Webhook::verifySignature(self::BODY, 't=soon,v1=00', self::SECRET, now: self::NOW), 'malformed_signature'],
            'no secrets' => [fn () => Webhook::verifySignature(self::BODY, $h, [], now: self::NOW), 'missing_secret'],
        ];
        foreach ($cases as $name => [$call, $expected]) {
            $this->assertSame($expected, self::refusal($call), $name);
        }
    }

    public function testEitherSecretVerifiesDuringARotation(): void
    {
        $this->assertSame('accepted', self::refusal(fn () => Webhook::verifySignature(self::BODY, self::header(self::NOW), ['whsec_new', self::SECRET], now: self::NOW)));
        $this->assertSame('invalid_signature', self::refusal(fn () => Webhook::verifySignature(self::BODY, self::header(self::NOW), ['whsec_new', 'whsec_newer'], now: self::NOW)));
    }

    public function testAPsr7HeaderArrayIsRead(): void
    {
        $this->assertSame('accepted', self::refusal(fn () => Webhook::verifySignature(self::BODY, [self::header(self::NOW)], self::SECRET, now: self::NOW)));
    }

    public function testSpacesAroundTheHeaderPartsAreTolerated(): void
    {
        $sig = hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET);
        $this->assertSame('accepted', self::refusal(fn () => Webhook::verifySignature(self::BODY, 't=' . self::NOW . ', v1=' . $sig, self::SECRET, now: self::NOW)));
    }

    public function testAVerifiedBodyThatIsNotAnEventIsRefused(): void
    {
        foreach (['not json', '[]', '{"type":"payment.captured"}', '{"id":1,"type":"x"}'] as $body) {
            $this->assertSame(
                'malformed_event',
                self::refusal(fn () => Webhook::parseEvent($body, self::header(self::NOW, $body), self::SECRET, now: self::NOW)),
                $body,
            );
        }
    }

    public function testAClaimedEventIsHandedOverOnceAndADuplicateIsNull(): void
    {
        $store = new MemoryWebhookEventStore();
        $first = Webhook::claimEvent(self::BODY, self::header(self::NOW), self::SECRET, $store, now: self::NOW);
        $again = Webhook::claimEvent(self::BODY, self::header(self::NOW + 30), self::SECRET, $store, now: self::NOW + 30);
        $this->assertSame('evt_1', $first['id'] ?? null);
        $this->assertNull($again, 'a retried delivery of the same event');
    }

    public function testTheMemoryStoreForgetsTheOldestWhenFull(): void
    {
        $store = new MemoryWebhookEventStore(2);
        $this->assertTrue($store->claim('a'));
        $this->assertTrue($store->claim('b'));
        $this->assertTrue($store->claim('c'));
        $this->assertFalse($store->claim('c'));
        $this->assertTrue($store->claim('a'), 'a was evicted');
    }

    public function testTheDefaultClockIsNow(): void
    {
        $t = time();
        $this->assertSame('accepted', self::refusal(fn () => Webhook::verifySignature(self::BODY, self::header($t), self::SECRET)));
    }
}
