<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Server-side request forgery (SSRF) guard for every address a user can type
 * into the system and the server then calls: a website webhook URL, a
 * WooCommerce site, a payment or SMS gateway, a file link returned by a remote
 * service.
 *
 * An address is allowed only when it
 *  - is a plain http(s) URL (https only unless the caller allows http), with no
 *    username/password part and no numeric/obfuscated host such as
 *    http://2130706433/ or http://0x7f.1/;
 *  - is not localhost, a single-label host (a Docker/internal service name) or
 *    a .local / .internal / .lan style name;
 *  - resolves only to public addresses: loopback, private (10/8, 172.16/12,
 *    192.168/16), link-local (169.254/16, includes the cloud metadata address),
 *    carrier-grade NAT, documentation and other reserved ranges, IPv6 unique
 *    local/link-local and IPv4-mapped IPv6 of any of these are refused.
 *
 * http() adds the two protections a one-off check cannot give: the checked
 * address is pinned for the connection (so a DNS answer that changes between the
 * check and the call - DNS rebinding - cannot point the request elsewhere), and
 * every redirect target is checked again.
 */
final class OutboundUrlGuard
{
    /** @var (Closure(string): array<int, string>)|null */
    private static ?Closure $resolver = null;

    /** Replaces DNS lookups (tests). Pass null to restore the real resolver. */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Null when the address may be called, otherwise the reason it may not. */
    public static function reason(string $url, bool $requireHttps = true): ?string
    {
        try {
            self::inspect($url, $requireHttps);
        } catch (UnsafeOutboundUrl $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    public static function isSafe(string $url, bool $requireHttps = true): bool
    {
        return self::reason($url, $requireHttps) === null;
    }

    /**
     * @return string the same URL, when it may be called
     *
     * @throws UnsafeOutboundUrl
     */
    public static function assertSafe(string $url, bool $requireHttps = true): string
    {
        self::inspect($url, $requireHttps);

        return $url;
    }

    /**
     * An HTTP client for one checked address: connection pinned to the checked
     * IP, redirects re-checked. Use it instead of Http::get()/post() for any
     * user-supplied URL.
     *
     * @throws UnsafeOutboundUrl
     */
    public static function http(string $url, bool $requireHttps = true, int $timeout = 20): PendingRequest
    {
        $inspected = self::inspect($url, $requireHttps);
        $requireHttps = self::httpsRequired($requireHttps);

        $options = [
            'allow_redirects' => [
                'max' => 3,
                'strict' => true,
                'protocols' => $requireHttps ? ['https'] : ['http', 'https'],
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($requireHttps): void {
                    self::inspect((string) $uri, $requireHttps);
                },
            ],
        ];

        if ($inspected['pin'] !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$inspected['pin']]];
        }

        return Http::timeout($timeout)->withOptions($options);
    }

    /** Whether an IP address is on the public internet (not private, loopback, link-local or reserved). */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // IPv4 inside IPv6 (::ffff:127.0.0.1, 64:ff9b::7f00:1): judge the IPv4 address.
        if (($embedded = self::embeddedIpv4($ip)) !== null) {
            return self::isPublicIp($embedded);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_V4 as [$network, $bits]) {
            if (self::inV4Range($ip, $network, $bits)) {
                return false;
            }
        }

        return true;
    }

    /** IPv4 ranges the PHP filter flags do not cover. */
    private const EXTRA_BLOCKED_V4 = [
        ['100.64.0.0', 10],  // carrier-grade NAT
        ['192.0.0.0', 24],   // IETF protocol assignments
        ['192.0.2.0', 24],   // documentation
        ['198.18.0.0', 15],  // benchmarking
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
    ];

    /**
     * @return array{host: string, pin: ?string}
     *
     * @throws UnsafeOutboundUrl
     */
    private static function inspect(string $url, bool $requireHttps): array
    {
        $requireHttps = self::httpsRequired($requireHttps);
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeOutboundUrl(__('The address must be a full URL such as https://example.com.'));
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'https' && ! ($scheme === 'http' && ! $requireHttps)) {
            throw new UnsafeOutboundUrl($requireHttps
                ? __('Only https:// addresses are allowed.')
                : __('Only http:// and https:// addresses are allowed.'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeOutboundUrl(__('The address must not contain a username or password.'));
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($host === '') {
            throw new UnsafeOutboundUrl(__('The address must be a full URL such as https://example.com.'));
        }

        $allowedHosts = array_map('strtolower', (array) config('outbound.allowed_hosts', []));

        if (config('outbound.allow_private_hosts') === true || in_array($host, $allowedHosts, true)) {
            return ['host' => $host, 'pin' => null];
        }

        // A literal IP address.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! self::isPublicIp($host)) {
                throw self::privateNetwork();
            }

            return ['host' => $host, 'pin' => null];
        }

        // 2130706433, 0x7f.1, 017700000001 ... are read as IP addresses by the HTTP client.
        if (preg_match('/^[0-9a-fx.]+$/i', $host) === 1 || ! str_contains($host, '.')) {
            throw self::privateNetwork();
        }

        if (preg_match('/(^|\.)(localhost|local|localdomain|internal|intranet|lan|home|corp|private|home\.arpa)$/', $host) === 1) {
            throw self::privateNetwork();
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            // Production refuses a name that does not resolve (nothing legitimate
            // is behind it, and a later answer could differ). Development and the
            // test suite keep working with made-up host names.
            if (app()->environment(['local', 'testing'])) {
                return ['host' => $host, 'pin' => null];
            }

            throw new UnsafeOutboundUrl(__('The address could not be resolved. Check the domain name.'));
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                throw self::privateNetwork();
            }
        }

        $pinned = collect($addresses)->first(fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) ?? $addresses[0];

        return ['host' => $host, 'pin' => $host.':'.$port.':'.$pinned];
    }

    /** A development machine allowed to reach private hosts may also use plain http. */
    private static function httpsRequired(bool $requireHttps): bool
    {
        return $requireHttps && config('outbound.allow_private_hosts') !== true;
    }

    private static function privateNetwork(): UnsafeOutboundUrl
    {
        return new UnsafeOutboundUrl(__('This address points to a private, local or reserved network and cannot be used.'));
    }

    /** @return array<int, string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values(array_unique((self::$resolver)($host)));
        }

        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $ip !== '') {
                $addresses[] = $ip;
            }
        }

        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }

    private static function embeddedIpv4(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $bytes = array_values(unpack('C*', $packed));

        // ::ffff:a.b.c.d (IPv4-mapped) and ::a.b.c.d (IPv4-compatible)
        $leadingZero = array_sum(array_slice($bytes, 0, 10)) === 0;
        $mapped = $leadingZero && $bytes[10] === 0xFF && $bytes[11] === 0xFF;
        $compatible = $leadingZero && $bytes[10] === 0 && $bytes[11] === 0 && array_sum(array_slice($bytes, 12)) > 1;
        // 64:ff9b::a.b.c.d (NAT64)
        $nat64 = array_slice($bytes, 0, 12) === [0x00, 0x64, 0xFF, 0x9B, 0, 0, 0, 0, 0, 0, 0, 0];

        if ($mapped || $compatible || $nat64) {
            return implode('.', array_slice($bytes, 12, 4));
        }

        return null;
    }

    private static function inV4Range(string $ip, string $network, int $bits): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF;

        return (ip2long($ip) & $mask) === (ip2long($network) & $mask);
    }
}
