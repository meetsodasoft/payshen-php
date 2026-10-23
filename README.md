# Payshen PHP

The PHP client for the [Payshen](https://www.payshen.com) API: create and route payments, capture, void and refund
them, read their timeline, and verify the webhooks Payshen sends.

Payshen routes each payment to one of the providers you already have (Stripe, Adyen, Checkout.com, payabl. and
more). Payshen never holds or moves money: payments run on your own provider accounts.

- PHP 8.2 or newer, with `ext-curl` and `ext-json`. No other dependencies.
- Every POST carries an `Idempotency-Key`, so a retry after a dropped connection never charges twice.
- Retries with backoff and jitter, on the answers worth retrying and never on the ones the API says not to.
- Typed exceptions with the API's own error code and the request id support will ask for.

## Keep PHP patched

Run a PHP that has the 21 October 2026 security release: **8.2.35, 8.3.36, 8.4.27, 8.5.12** or newer (or your
Linux distribution's patched package). It fixes CVE-2026-109618 and CVE-2026-109619 ("PHP2Shell"), two flaws in PHP
itself that let anyone run code on a server that handles web requests, before any application code runs. This
library contains no affected code and cannot protect against them; only updating PHP does. See
[php.net](https://www.php.net/) and [Payshen's notice](https://www.payshen.com/security/advisories/php2shell).

## Install

```bash
composer require payshen/payshen-php
```

## Create a payment

```php
$payshen = new Payshen\Payshen(getenv('PAYSHEN_API_KEY')); // ps_test_... in the sandbox

$payment = $payshen->payments->create([
    'amount' => 5000,              // minor units: EUR 50.00
    'currency' => 'EUR',
    'country' => 'DE',
    'reference' => 'order-1024',
    'capture_method' => 'manual',  // hold now, capture when you ship
    'return_url' => 'https://yourshop.com/checkout/return',
], ['idempotency_key' => 'order-1024']);

// REQUIRES_ACTION: send the customer to the provider's page.
header('Location: ' . $payment['next_action']['url']);
```

The customer pays on the provider's page and comes back to your `return_url`. The return is not the outcome:
the outcome is the webhook (`payment.authorized`, `payment.captured`, `payment.declined`, ...).

## Capture, void, refund

```php
use Payshen\Exception\PayshenException;

$payshen->payments->capture($paymentId, [], ['idempotency_key' => 'capture-order-1024']);           // in full
$payshen->payments->capture($paymentId, ['amount' => 3000], ['idempotency_key' => 'capture-order-1024']); // or in part
$payshen->payments->void($paymentId, ['idempotency_key' => 'void-order-1024']);                      // before capture

try {
    $payshen->payments->refund($paymentId, ['amount' => 2000], ['idempotency_key' => 'refund-order-1024-1']);
} catch (PayshenException $e) {
    // 409 refund_pending: accepted, not failed. The provider confirms a moment later and
    // payment.refunded or payment.refund_failed follows. Do not send it again under a new key.
    if ($e->errorCode !== 'refund_pending') {
        throw $e;
    }
}
```

A capture or void a provider only accepted answers with the payment `PENDING`; it becomes `CAPTURED` or
`CANCELLED` when the provider confirms, and the webhook follows.

## Read

```php
$payment = $payshen->payments->get($paymentId);
$timeline = $payshen->payments->events($paymentId);

$page = $payshen->payments->list(['limit' => 50, 'status' => 'captured']);
foreach ($payshen->payments->all(['status' => 'captured']) as $payment) {
    // every page, newest first
}

foreach ($payshen->events->all(['type' => 'payment.captured']) as $event) {
    // the same events webhooks deliver, for polling
}
```

## Webhooks

```php
use Payshen\Exception\SignatureVerificationException;
use Payshen\Webhook;

$raw = file_get_contents('php://input'); // the RAW body: a body re-encoded from json_decode never verifies

$secret = getenv('PAYSHEN_WEBHOOK_SECRET');
if (!is_string($secret) || $secret === '') {
    throw new RuntimeException('PAYSHEN_WEBHOOK_SECRET is not set'); // never verify against an empty secret
}

try {
    $event = Webhook::parseEvent($raw, $_SERVER['HTTP_X_PAYSHEN_SIGNATURE'] ?? null, $secret);
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

// Deliveries are retried, so the same event can arrive twice. Record $event['id']
// and skip one you have already processed.
switch ($event['type']) {
    case 'payment.captured':
        // fulfil $event['data']['reference']
        break;
}
http_response_code(204);
```

The signature proves a delivery came from Payshen and is recent (5 minutes by default); it does not make it new.
`Webhook::claimEvent($raw, $header, $secret, $store)` verifies, parses and takes each event once, with a
`WebhookEventStore` you back with your database:

```php
final class DbEventStore implements Payshen\WebhookEventStore
{
    public function __construct(private PDO $db) {}

    public function claim(string $eventId): bool
    {
        // MySQL; PostgreSQL: INSERT ... ON CONFLICT (id) DO NOTHING
        $stmt = $this->db->prepare('INSERT IGNORE INTO processed_webhook_events (id) VALUES (?)');
        $stmt->execute([$eventId]);
        return $stmt->rowCount() === 1;
    }
}
```

During a secret rotation pass both secrets: `Webhook::parseEvent($raw, $header, [$new, $old])`.

## Errors

| Exception | When |
|---|---|
| `AuthenticationException` | 401 or 403: a missing, wrong or revoked key, or one not allowed this call |
| `ValidationException` | 422: refused as sent; `errorCode` names the rule |
| `RateLimitException` | 429 after the client's retries; `retryAfterSeconds` when the API said |
| `NetworkException` | No answer at all. On a POST, retry with the same idempotency key |
| `PayshenException` | Everything else, and the parent of all of the above |

Every exception has `status`, `errorCode` (`refund_pending`, `not_capturable`, `payment_result_unknown`, ...) and
`requestId`. Branch on `errorCode`, not on the message.

## Options

```php
new Payshen\Payshen($key, [
    'timeout' => 20,        // seconds per attempt
    'max_retries' => 3,
    'base_url' => 'https://www.payshen.com',
    'ca_bundle' => null,    // a PEM file, for a PHP with no trusted roots configured
]);
```

`$payshen->request('GET', '/api/v1/...')` calls any endpoint and returns the whole answer.

## Documentation

- API reference and quickstart: <https://www.payshen.com/developers>
- OpenAPI 3.1: <https://www.payshen.com/api/v1/openapi.json>

## License

MIT
