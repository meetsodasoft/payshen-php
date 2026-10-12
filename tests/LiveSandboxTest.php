<?php

declare(strict_types=1);

namespace Payshen\Tests;

use Payshen\Exception\AuthenticationException;
use Payshen\Exception\PayshenException;
use Payshen\Payshen;
use PHPUnit\Framework\TestCase;

/**
 * Against the real API over the real transport (cURL, TLS), with a sandbox
 * key. Skipped unless PAYSHEN_TEST_KEY is set; PAYSHEN_BASE_URL points it at
 * another deployment. Nothing here moves money: a test key only reaches
 * provider sandboxes, and the payment it makes is left waiting on its page.
 */
final class LiveSandboxTest extends TestCase
{
    private static function client(): Payshen
    {
        $key = getenv('PAYSHEN_TEST_KEY');
        if (!is_string($key) || !str_starts_with($key, 'ps_test_')) {
            self::markTestSkipped('PAYSHEN_TEST_KEY (a ps_test_ key) is not set.');
        }
        $base = getenv('PAYSHEN_BASE_URL');
        return new Payshen($key, is_string($base) && $base !== '' ? ['base_url' => $base] : []);
    }

    public function testAHostedPaymentFromCreateToItsTimeline(): void
    {
        $client = self::client();
        $reference = 'php-sdk-' . bin2hex(random_bytes(4));
        $payment = $client->payments->create([
            'amount' => 1250,
            'currency' => 'EUR',
            'country' => 'DE',
            'method' => 'card',
            'reference' => $reference,
            'return_url' => 'https://example.com/payshen-return',
        ], ['idempotency_key' => $reference]);

        $this->assertSame('REQUIRES_ACTION', $payment['status']);
        $this->assertStringStartsWith('https://', $payment['next_action']['url']);
        $id = $payment['payment_id'] ?? $payment['id'];

        $again = $client->payments->create([
            'amount' => 1250,
            'currency' => 'EUR',
            'country' => 'DE',
            'method' => 'card',
            'reference' => $reference,
            'return_url' => 'https://example.com/payshen-return',
        ], ['idempotency_key' => $reference]);
        $this->assertSame($id, $again['payment_id'] ?? $again['id'], 'the same key answers the same payment');

        $read = $client->payments->get($id);
        $this->assertSame($reference, $read['reference']);
        $this->assertSame(1250, $read['amount']);

        $events = $client->payments->events($id);
        $this->assertContains('payment.created', array_column($events, 'type'));

        $page = $client->payments->list(['limit' => 5]);
        $this->assertContains($id, array_map(static fn (array $p): string => $p['payment_id'] ?? $p['id'], $page->data));

        try {
            $client->payments->capture($id);
            $this->fail('a payment waiting on its customer cannot be captured');
        } catch (PayshenException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('not_capturable', $e->errorCode);
            $this->assertNotNull($e->requestId);
        }
    }

    public function testAWrongKeyIsAnAuthenticationError(): void
    {
        self::client();
        $base = getenv('PAYSHEN_BASE_URL');
        $wrong = new Payshen('ps_test_not_a_real_key', is_string($base) && $base !== '' ? ['base_url' => $base] : []);
        $this->expectException(AuthenticationException::class);
        $wrong->payments->list();
    }
}
