# Changelog

## 0.2.1

2026-10-24. Security fixes; upgrade. Your code does not change.

- **The API key stays out of dumps and traces.** `var_dump` and `print_r` of the client (or of a service, which holds
  it) show `ps_live_…1a2b` instead of the key. The key constructor argument and the transport's `send()` headers are
  marked `#[\SensitiveParameter]`, so an exception trace with arguments shows `SensitiveParameterValue` in their
  place. A custom `Transport` should mark its own `$headers` the same way.
- **Ids are checked before they become part of the path**: 1 to 128 letters, digits, `_` or `-`, or
  `InvalidArgumentException` and nothing is sent. Before, `payments->get('.')` read the payment list and
  `payments->refund('..')` posted to another route, because `.` and `..` pass URL encoding unchanged.

## 0.2.0

2026-10-23. Requires PHP 8.2 or newer. Your code does not change.

- **Update PHP itself first.** PHP's 21 October 2026 security release (8.2.35, 8.3.36, 8.4.27, 8.5.12) fixes
  CVE-2026-109618 and CVE-2026-109619 ("PHP2Shell"): unauthenticated remote code execution in PHP's request handling,
  exploited in the wild. The flaws are in PHP, not in this library, and no version of this library prevents them.
- **PHP 8.1 is no longer supported.** It reached end of life in December 2025 and has no official fix for these
  flaws. On PHP 8.1 Composer keeps installing 0.1.2, which keeps working; move to a supported PHP to get later releases.
- Tested on PHP 8.2, 8.3, 8.4 and 8.5.

## 0.1.2

2026-10-17. Security fixes; upgrade.

- **Webhook verification never accepts an empty secret.** `Webhook::verifySignature` and `parseEvent` ignore an empty
  or non-string secret, and refuse with `missing_secret` when none is left. Before, a secret read from an unset
  environment variable (`getenv()` returns `false`, which becomes `''` without strict types), or an empty old secret in
  a rotation list, let anyone sign a forged event with an empty key.
- **`idempotency_key` is checked before it is sent**: 8 to 200 printable ASCII characters, the API's own rule. A key
  with a line break could add request headers of its own.
- **`base_url` must be `https://`** (`http://` for localhost only), and curl is limited to HTTP(S).
- The README's webhook example fails fast when the secret is not set.

## 0.1.1

2026-10-14. Additive; nothing you use changes.

- `PayshenException::$refundId`: a refund answered `refund_pending`, `refund_result_unknown`, `psp_error` or
  `state_unconfirmed` names the refund it is about, for the `payment.refunded` or `payment.refund_failed` webhook that
  settles it. A successful refund's answer now carries `refund` (`id`, `amount`, `status`) beside the payment's figures;
  it comes through as part of the returned array.

## 0.1.0

The first release, with the same rules as the TypeScript SDK (`@payshen/sdk` 0.9.3).

- Payments: create, get, list, `all()` over every page, events, capture (full or partial), void, refund (full or
  partial).
- Events feed, insights, suggestions (list, get, accept, reject, snooze), partner merchants.
- An `Idempotency-Key` on every POST, yours or generated and reused across retries.
- Retries on 408, 425, 429, 500, 502, 503 and 504 with backoff and jitter, never on an outcome the API says not to
  retry (`payment_result_unknown`, `*_result_unknown`, `state_unconfirmed`, `state_conflict`, `psp_error`,
  `provider_unavailable`, `merchant_limit`). `Retry-After` is honoured, capped at 4 seconds.
- Webhooks: `verifySignature`, `parseEvent`, `claimEvent` with a `WebhookEventStore`, and secret rotation.
- HTTPS only, except localhost; redirects are never followed. On Windows without a configured CA bundle, the
  system's trusted roots are used.
