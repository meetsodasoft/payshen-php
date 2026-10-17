<?php

declare(strict_types=1);

namespace Payshen;

use Payshen\Exception\SignatureVerificationException;

/**
 * Webhook deliveries: verify, parse, and take each event once.
 *
 *     $raw = file_get_contents('php://input');   // the RAW body, before json_decode
 *     try {
 *         $event = Payshen\Webhook::parseEvent($raw, $_SERVER['HTTP_X_PAYSHEN_SIGNATURE'] ?? null, $secret);
 *     } catch (Payshen\Exception\SignatureVerificationException $e) {
 *         http_response_code(400);
 *         exit;
 *     }
 *
 * The signature proves the delivery came from Payshen and is recent. It does
 * not make it new: deliveries are retried, so deduplicate on `$event['id']`
 * (or use `claimEvent`).
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Check the `X-Payshen-Signature` header (`t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">`).
     *
     * @param string|list<string>|null $header the header value (or a PSR-7 header array)
     * @param string|list<string> $secret the endpoint's signing secret, or several during a rotation
     *
     * @throws SignatureVerificationException
     */
    public static function verifySignature(
        string $payload,
        string|array|null $header,
        string|array $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): void {
        if (is_array($header)) {
            $header = $header[0] ?? null;
        }
        if ($header === null || $header === '') {
            throw new SignatureVerificationException('missing_signature', 'Missing X-Payshen-Signature header.');
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', $part, 2);
            $parts[trim($kv[0])] = trim($kv[1] ?? '');
        }
        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if (preg_match('/^\d+$/', $t) !== 1 || $v1 === '') {
            throw new SignatureVerificationException('malformed_signature', 'Signature is malformed.');
        }

        $now ??= time();
        if (abs($now - (int) $t) > $toleranceSeconds) {
            throw new SignatureVerificationException('expired_signature', 'Signature timestamp outside tolerance window.');
        }

        // An empty secret signs with an empty key, which anyone can do: it is
        // never a candidate. getenv() of an unset variable is false, which
        // becomes '' in a caller without strict types, and a rotation list may
        // carry an old secret that is no longer set. With nothing left, refuse.
        $secrets = array_values(array_filter(
            is_array($secret) ? $secret : [$secret],
            static fn ($s): bool => is_string($s) && $s !== '',
        ));
        if ($secrets === []) {
            throw new SignatureVerificationException('missing_secret', 'No signing secret was provided.');
        }

        // Every candidate is checked, timing-safe each time, so a rotation does
        // not turn into an oracle for which secret is live.
        $matched = false;
        foreach ($secrets as $candidate) {
            if (hash_equals(hash_hmac('sha256', $t . '.' . $payload, (string) $candidate), $v1)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new SignatureVerificationException('invalid_signature', 'Signature does not match payload.');
        }
    }

    /**
     * Verify, then return the event (`id`, `type`, `created`, `data`).
     *
     * @param string|list<string>|null $header
     * @param string|list<string> $secret
     * @return array<string, mixed>
     *
     * @throws SignatureVerificationException also `malformed_event` for a verified body that is not a Payshen event
     */
    public static function parseEvent(
        string $payload,
        string|array|null $header,
        string|array $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): array {
        self::verifySignature($payload, $header, $secret, $toleranceSeconds, $now);
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SignatureVerificationException('malformed_event', 'Verified body is not valid JSON.');
        }
        if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['type'] ?? null)) {
            throw new SignatureVerificationException('malformed_event', 'Verified body is not a Payshen event envelope.');
        }
        return $event;
    }

    /**
     * Verify, parse and claim: the event when this delivery is the first sight
     * of it, `null` when it was already handled. Answer a `null` with 2xx: a
     * duplicate that keeps being retried is worse than a duplicate.
     *
     * The claim happens BEFORE your work: if the process dies in between, the
     * event is marked handled and never was. Where that matters (anything that
     * moves goods or money), claim and work in one transaction with your own
     * store instead.
     *
     * @param string|list<string>|null $header
     * @param string|list<string> $secret
     * @return array<string, mixed>|null
     */
    public static function claimEvent(
        string $payload,
        string|array|null $header,
        string|array $secret,
        WebhookEventStore $store,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): ?array {
        $event = self::parseEvent($payload, $header, $secret, $toleranceSeconds, $now);
        return $store->claim($event['id']) ? $event : null;
    }
}
