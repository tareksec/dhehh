<?php

namespace App\Support;

use App\Services\LoginGate;
use Illuminate\Http\Request;

/**
 * Who is behind a sign-in request, as far as LoginGate can tell: network,
 * browser, language and the device-trust token the browser (or, for the
 * Next.js site, its server) sent back.
 *
 * The headless Next.js site reaches the ERP from ONE server address, so for
 * its requests the visitor's own IP and browser come from the fields the site
 * forwards (client_ip, client_user_agent) — the same trust the per-visitor
 * "storefront-auth" limiter already places in the API key holder.
 */
final class LoginClient
{
    public function __construct(
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $language,
        public readonly ?string $deviceToken,
    ) {}

    /** A browser talking to the ERP directly: the token is a cookie. */
    public static function fromRequest(Request $request, string $surface): self
    {
        $token = $request->cookie(LoginGate::cookieName($surface));

        return new self(
            (string) $request->ip(),
            self::clean((string) $request->userAgent()),
            self::clean((string) $request->header('Accept-Language')),
            is_string($token) && $token !== '' ? $token : null,
        );
    }

    /** The Next.js site's server forwarding a visitor's sign-in. */
    public static function fromApiRequest(Request $request): self
    {
        $ip = $request->input('client_ip');
        $agent = $request->input('client_user_agent');
        $token = $request->input('device_token', $request->header('X-Device-Token'));

        return new self(
            is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : (string) $request->ip(),
            is_string($agent) ? self::clean($agent) : '',
            '',
            is_string($token) && $token !== '' ? $token : null,
        );
    }

    /**
     * The network part of the address: /24 for IPv4, /48 for IPv6. One bot
     * farm tends to sit inside one such block; one visitor moving between
     * addresses of their provider stays in it.
     */
    public function network(): string
    {
        $packed = @inet_pton($this->ip);

        if ($packed === false) {
            return $this->ip;
        }

        return strlen($packed) === 4
            ? implode('.', array_slice(array_map('ord', str_split($packed)), 0, 3)).'.0/24'
            : bin2hex(substr($packed, 0, 6)).'::/48';
    }

    private static function clean(string $value): string
    {
        return mb_substr(trim($value), 0, 255);
    }
}
