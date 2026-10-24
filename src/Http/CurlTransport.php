<?php

declare(strict_types=1);

namespace Payshen\Http;

/** The default transport: ext-curl, TLS verified, no redirects followed. */
final class CurlTransport implements Transport
{
    /**
     * @param string|null $caBundle a PEM file of trusted roots, for a server
     *                              whose PHP has none configured
     */
    public function __construct(
        private readonly float $connectTimeoutSeconds = 10.0,
        private readonly ?string $caBundle = null,
    ) {
    }

    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body, float $timeoutSeconds): Response
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportException('curl_init failed.');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // No "Expect: 100-continue" round trip on larger bodies.
        $lines[] = 'Expect:';

        $received = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            // A redirect on an API call is a misconfigured base URL; following
            // it would turn a POST into a GET or send the key somewhere else.
            CURLOPT_FOLLOWLOCATION => false,
            // HTTP(S) only, whatever the URL says: no file://, ftp:// or the like.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($this->caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        } elseif (self::needsNativeRoots()) {
            // PHP on Windows ships without a CA bundle unless php.ini names one,
            // and every HTTPS call then fails with curl error 60. Windows has
            // its own trusted roots: use those.
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 16);
        }

        $answer = curl_exec($ch);
        if ($answer === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            throw new TransportException(sprintf('Request to Payshen failed: %s (curl %d).', $error, $errno));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return new Response($status, $received, is_string($answer) ? $answer : '');
    }

    private static function needsNativeRoots(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || (string) ini_get('curl.cainfo') !== '' || (string) ini_get('openssl.cafile') !== '') {
            return false;
        }
        $version = curl_version();
        // CURLSSLOPT_NATIVE_CA arrived in libcurl 7.71.0.
        return is_array($version) && ($version['version_number'] ?? 0) >= 0x074700;
    }
}
