<?php

declare(strict_types=1);

namespace Payshen\Tests;

use Payshen\Exception\AuthenticationException;
use Payshen\Exception\NetworkException;
use Payshen\Exception\PayshenException;
use Payshen\Exception\RateLimitException;
use Payshen\Exception\ValidationException;
use Payshen\Http\TransportException;
use Payshen\Payshen;
use PHPUnit\Framework\TestCase;

/**
 * The client against a recorded transport: what it sends, how it retries, and
 * what it throws, the same rules as the TypeScript SDK.
 */
final class ClientTest extends TestCase
{
    /** @var list<int> milliseconds the client asked to wait */
    private array $slept = [];

    private function client(FakeTransport $transport, array $options = []): Payshen
    {
        return new Payshen('ps_test_abc', $options + [
            'transport' => $transport,
            'sleep' => function (int $ms): void {
                $this->slept[] = $ms;
            },
        ]);
    }

    private const PAYMENT = ['payment_id' => 'pay_1', 'status' => 'REQUIRES_ACTION', 'next_action' => ['type' => 'REDIRECT', 'url' => 'https://pay.example/next']];

    public function testCreateSendsTheRequestTheApiDocuments(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, ['data' => self::PAYMENT]));
        $payment = $this->client($t)->payments->create(
            ['amount' => 5000, 'currency' => 'EUR', 'country' => 'DE', 'reference' => 'order-1024', 'return_url' => 'https://shop.example/return'],
            ['idempotency_key' => 'order-1024'],
        );

        $this->assertSame('https://pay.example/next', $payment['next_action']['url']);
        $sent = $t->sent[0];
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('https://www.payshen.com/api/v1/payments', $sent['url']);
        $this->assertSame('Bearer ps_test_abc', $sent['headers']['Authorization']);
        $this->assertSame('order-1024', $sent['headers']['Idempotency-Key']);
        $this->assertSame('application/json', $sent['headers']['Content-Type']);
        $this->assertStringStartsWith('payshen-php/' . Payshen::VERSION . ' PHP/', $sent['headers']['User-Agent']);
        $this->assertSame(
            ['amount' => 5000, 'currency' => 'EUR', 'country' => 'DE', 'reference' => 'order-1024', 'return_url' => 'https://shop.example/return'],
            json_decode((string) $sent['body'], true),
        );
        $this->assertSame([], $this->slept);
    }

    public function testAnIdempotencyKeyThatCouldSplitAHeaderIsRefusedBeforeSending(): void
    {
        $t = new FakeTransport();
        foreach (["order-1\r\nX-Injected: yes", "order-1\nX", 'short', str_repeat('k', 201)] as $bad) {
            try {
                $this->client($t)->payments->create(['amount' => 1000, 'currency' => 'EUR', 'country' => 'DE'], ['idempotency_key' => $bad]);
                $this->fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('idempotency_key', $e->getMessage());
            }
        }
        $this->assertSame([], $t->sent, 'nothing is sent');
    }

    public function testOnlyAnHttpsBaseUrlIsAccepted(): void
    {
        foreach (['ftp://api.payshen.com', 'file:///etc/passwd', 'http://api.example.com'] as $bad) {
            try {
                new Payshen('ps_test_abc', ['base_url' => $bad, 'transport' => new FakeTransport()]);
                $this->fail('accepted ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('https', $e->getMessage());
            }
        }
        new Payshen('ps_test_abc', ['base_url' => 'http://localhost:8080', 'transport' => new FakeTransport()]);
        $this->addToAssertionCount(1);
    }

    public function testEveryPostCarriesAKeyEvenWhenTheCallerGaveNone(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, ['data' => self::PAYMENT]), FakeTransport::json(200, ['data' => self::PAYMENT]));
        $client = $this->client($t);
        $client->payments->create(['amount' => 100]);
        $client->payments->get('pay_1');

        $this->assertMatchesRegularExpression('/^sdk_[0-9a-f]{32}$/', $t->sent[0]['headers']['Idempotency-Key']);
        $this->assertArrayNotHasKey('Idempotency-Key', $t->sent[1]['headers'], 'a GET needs none');
        $this->assertNull($t->sent[1]['body']);
        $this->assertArrayNotHasKey('Content-Type', $t->sent[1]['headers']);
    }

    public function testAnEmptyBodyIsTheEmptyObjectNotAList(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['data' => ['status' => 'CAPTURED']]), FakeTransport::json(200, ['data' => ['status' => 'CANCELLED']]));
        $client = $this->client($t);
        $client->payments->capture('pay_1');
        $client->payments->void('pay_2');

        $this->assertSame('{}', $t->sent[0]['body']);
        $this->assertSame('{}', $t->sent[1]['body']);
        $this->assertSame('https://www.payshen.com/api/v1/payments/pay_1/capture', $t->sent[0]['url']);
        $this->assertSame('https://www.payshen.com/api/v1/payments/pay_2/void', $t->sent[1]['url']);
    }

    public function testAnIdThatIsNotAnIdIsRefusedBeforeSending(): void
    {
        $t = new FakeTransport();
        $client = $this->client($t);
        $calls = [
            'payments.get' => fn (string $id) => $client->payments->get($id),
            'payments.events' => fn (string $id) => $client->payments->events($id),
            'payments.capture' => fn (string $id) => $client->payments->capture($id),
            'payments.void' => fn (string $id) => $client->payments->void($id),
            'payments.refund' => fn (string $id) => $client->payments->refund($id),
            'suggestions.get' => fn (string $id) => $client->suggestions->get($id),
            'suggestions.accept' => fn (string $id) => $client->suggestions->accept($id),
            'suggestions.reject' => fn (string $id) => $client->suggestions->reject($id),
            'suggestions.snooze' => fn (string $id) => $client->suggestions->snooze($id),
        ];
        // "." would read the list, ".." another route; the rest are not ids.
        foreach ($calls as $name => $call) {
            foreach (['', '.', '..', 'a/b?c', 'pay_1/../x', "pay_1\n", 'pay 1', str_repeat('a', 129)] as $bad) {
                try {
                    $call($bad);
                    $this->fail($name . ' accepted ' . json_encode($bad));
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString('id must be', $e->getMessage());
                }
            }
        }
        $this->assertSame([], $t->sent, 'nothing is sent');
    }

    public function testAnIdOfLettersDigitsUnderscoreAndDashGoesIntoThePath(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['data' => self::PAYMENT]), FakeTransport::json(200, ['data' => []]));
        $this->client($t)->payments->get('pay_01J-xY9');
        $this->client($t)->suggestions->get(str_repeat('s', 128));
        $this->assertSame('https://www.payshen.com/api/v1/payments/pay_01J-xY9', $t->sent[0]['url']);
        $this->assertSame('https://www.payshen.com/api/v1/suggestions/' . str_repeat('s', 128), $t->sent[1]['url']);
    }

    public function testARetryReusesTheSameKeySoItCannotChargeTwice(): void
    {
        $t = new FakeTransport(
            new TransportException('connection reset'),
            FakeTransport::json(503, ['error' => ['code' => 'internal_error', 'message' => 'busy']]),
            FakeTransport::json(201, ['data' => self::PAYMENT]),
        );
        $payment = $this->client($t)->payments->create(['amount' => 100]);

        $this->assertSame('pay_1', $payment['payment_id']);
        $this->assertCount(3, $t->sent);
        $keys = array_unique(array_map(static fn (array $s): string => $s['headers']['Idempotency-Key'], $t->sent));
        $this->assertCount(1, $keys, 'one key across every attempt');
        $this->assertCount(2, $this->slept);
        $this->assertGreaterThanOrEqual(250, $this->slept[0]);
        $this->assertLessThanOrEqual(4000, max($this->slept));
    }

    public function testAnOutcomeTheApiSaysNotToRetryIsNotRetried(): void
    {
        $t = new FakeTransport(FakeTransport::json(502, ['error' => ['code' => 'payment_result_unknown', 'message' => 'The provider did not answer.']], ['X-Request-Id' => 'req_9']));
        try {
            $this->client($t)->payments->create(['amount' => 100]);
            $this->fail('expected an exception');
        } catch (PayshenException $e) {
            $this->assertSame(502, $e->status);
            $this->assertSame('payment_result_unknown', $e->errorCode);
            $this->assertSame('req_9', $e->requestId);
        }
        $this->assertCount(1, $t->sent);
    }

    public function testRefundPendingIsThrownWithItsCodeAndNotRetried(): void
    {
        $t = new FakeTransport(FakeTransport::json(409, ['error' => ['code' => 'refund_pending', 'message' => 'Accepted, not refunded yet.']]));
        try {
            $this->client($t)->payments->refund('pay_1', ['amount' => 2000], ['idempotency_key' => 'refund-1']);
            $this->fail('expected an exception');
        } catch (PayshenException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('refund_pending', $e->getErrorCode());
        }
        $this->assertCount(1, $t->sent);
        $this->assertSame('{"amount":2000}', $t->sent[0]['body']);
        $this->assertSame('refund-1', $t->sent[0]['headers']['Idempotency-Key']);
    }

    public function testRateLimitHonoursRetryAfterCappedThenThrows(): void
    {
        $limited = FakeTransport::json(429, ['error' => ['code' => 'rate_limited', 'message' => 'Slow down.']], ['Retry-After' => '30']);
        $t = new FakeTransport($limited, $limited, $limited, $limited);
        try {
            $this->client($t)->payments->list();
            $this->fail('expected an exception');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->retryAfterSeconds);
            $this->assertSame(429, $e->status);
        }
        $this->assertCount(4, $t->sent, 'the first try and three retries');
        $this->assertSame([4000, 4000, 4000], $this->slept, 'a server asking for 30 s cannot park the caller');
    }

    public function testAuthAndValidationErrorsAreTypedAndNotRetried(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'Invalid API key.']]),
            FakeTransport::json(422, ['error' => ['code' => 'invalid_amount', 'message' => 'amount must be 1..500.']]),
        );
        $client = $this->client($t);
        try {
            $client->payments->get('pay_1');
            $this->fail('expected an exception');
        } catch (AuthenticationException $e) {
            $this->assertSame('invalid_api_key', $e->errorCode);
        }
        try {
            $client->payments->refund('pay_1', ['amount' => 600]);
            $this->fail('expected an exception');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_amount', $e->errorCode);
            $this->assertSame('amount must be 1..500.', $e->getMessage());
        }
        $this->assertCount(2, $t->sent);
        $this->assertSame([], $this->slept);
    }

    public function testRoutingFailedCarriesWhyEachConnectionWasLeftOut(): void
    {
        $excluded = [['connection' => 'Stripe EU', 'reason' => 'currency_not_supported']];
        $t = new FakeTransport(FakeTransport::json(422, ['error' => ['code' => 'routing_failed', 'message' => 'No connection can take it.', 'excluded' => $excluded]]));
        try {
            $this->client($t)->payments->create(['amount' => 100]);
            $this->fail('expected an exception');
        } catch (PayshenException $e) {
            $this->assertSame($excluded, $e->excluded);
        }
    }

    public function testAPendingRefundNamesTheRefundItIsAbout(): void
    {
        $t = new FakeTransport(FakeTransport::json(409, ['error' => ['code' => 'refund_pending', 'message' => 'Still processing.', 'refund_id' => 'b1f0c2d4-0000-4000-8000-000000000001']]));
        try {
            $this->client($t)->payments->refund('pay_1', ['amount' => 200]);
            $this->fail('expected an exception');
        } catch (PayshenException $e) {
            $this->assertSame('refund_pending', $e->errorCode);
            $this->assertSame('b1f0c2d4-0000-4000-8000-000000000001', $e->refundId);
        }
        $t2 = new FakeTransport(FakeTransport::json(422, ['error' => ['code' => 'invalid_amount', 'message' => 'No.']]));
        try {
            $this->client($t2)->payments->refund('pay_1', ['amount' => 9]);
        } catch (PayshenException $e) {
            $this->assertNull($e->refundId);
        }
    }

    public function testNoAnswerAtAllBecomesANetworkErrorAfterTheRetries(): void
    {
        $t = new FakeTransport(new TransportException('timeout'), new TransportException('timeout'), new TransportException('timeout'), new TransportException('timeout'));
        $this->expectException(NetworkException::class);
        try {
            $this->client($t)->payments->get('pay_1');
        } finally {
            $this->assertCount(4, $t->sent);
        }
    }

    public function testAnErrorWithoutABodyStillSaysWhatHappened(): void
    {
        $t = new FakeTransport(new \Payshen\Http\Response(404, [], ''));
        try {
            $this->client($t)->payments->get('missing');
            $this->fail('expected an exception');
        } catch (PayshenException $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('unknown_error', $e->errorCode);
            $this->assertSame('Request failed with status 404.', $e->getMessage());
        }
    }

    public function testListReadsTheCursorBesideDataAndAllWalksEveryPage(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(200, ['data' => [['payment_id' => 'p3'], ['payment_id' => 'p2']], 'next_cursor' => 'c2', 'has_more' => true]),
            FakeTransport::json(200, ['data' => [['payment_id' => 'p1']], 'next_cursor' => null, 'has_more' => false]),
        );
        $ids = [];
        foreach ($this->client($t)->payments->all(['limit' => 2, 'status' => 'captured']) as $p) {
            $ids[] = $p['payment_id'];
        }
        $this->assertSame(['p3', 'p2', 'p1'], $ids);
        $this->assertSame('https://www.payshen.com/api/v1/payments?limit=2&status=captured', $t->sent[0]['url']);
        $this->assertSame('https://www.payshen.com/api/v1/payments?limit=2&status=captured&before=c2', $t->sent[1]['url']);
    }

    public function testSuggestionStatesAreSentCommaSeparated(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['data' => []]));
        $page = $this->client($t)->suggestions->list(['state' => ['open', 'snoozed']]);
        $this->assertSame([], $page->data);
        $this->assertFalse($page->hasMore);
        $this->assertSame('https://www.payshen.com/api/v1/suggestions?state=open%2Csnoozed', $t->sent[0]['url']);
    }

    public function testTheKeyIsNeverSentOverPlainHttp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Payshen('ps_live_x', ['base_url' => 'http://api.example.com']);
    }

    public function testLocalhostOverHttpIsAllowedForAMockServer(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['data' => self::PAYMENT]));
        $this->client($t, ['base_url' => 'http://127.0.0.1:4010/'])->payments->get('pay_1');
        $this->assertSame('http://127.0.0.1:4010/api/v1/payments/pay_1', $t->sent[0]['url']);
    }

    public function testADumpOfTheClientNeverShowsTheKey(): void
    {
        $key = 'ps_live_4f9c2a7e1b8d6053sEcReT9x7Q';
        $client = new Payshen($key, ['transport' => new FakeTransport()]);

        ob_start();
        var_dump($client);
        $dumped = (string) ob_get_clean();
        $printed = print_r($client, true);
        // A service holds the client, so dumping one dumps the client too.
        $service = print_r($client->payments, true);

        foreach ([$dumped, $printed, $service] as $out) {
            $this->assertStringNotContainsString($key, $out);
            $this->assertStringNotContainsString('sEcReT', $out);
            $this->assertStringContainsString('ps_live_…9x7Q', $out);
        }
        // Too short to show any of it.
        $this->assertStringContainsString('[redacted]', print_r($this->client(new FakeTransport()), true));
        $this->assertStringNotContainsString('ps_test_abc', print_r($this->client(new FakeTransport()), true));
    }

    public function testTheKeyIsLeftOutOfExceptionTraces(): void
    {
        // Traces carry arguments unless php.ini drops them; make sure they are on.
        $previous = ini_set('zend.exception_ignore_args', '0');
        $key = 'ps_live_4f9c2a7e1b8d6053sEcReT9x7Q';
        try {
            try {
                new Payshen($key, ['base_url' => 'ftp://api.payshen.com']);
                $this->fail('accepted ftp');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString('sEcReT', print_r($e->getTrace(), true));
            }
            // The real transport, against a port nothing listens on: the trace
            // passes through send() with the Authorization header.
            try {
                (new Payshen($key, ['base_url' => 'http://127.0.0.1:9', 'max_retries' => 0, 'timeout' => 2]))->payments->get('pay_1');
                $this->fail('no exception');
            } catch (NetworkException $e) {
                $this->assertInstanceOf(TransportException::class, $e->getPrevious());
                $trace = print_r($e->getPrevious()->getTrace(), true);
                $this->assertStringContainsString('SensitiveParameterValue', $trace);
                $this->assertStringNotContainsString('sEcReT', $trace);
                $this->assertStringNotContainsString('sEcReT', print_r($e->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testAKeyThatIsNotAPayshenKeyIsRefusedAtOnce(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Payshen('sk_live_123');
    }
}
