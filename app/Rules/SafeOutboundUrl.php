<?php

namespace App\Rules;

use App\Support\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An address the server will call on its own (webhook, WooCommerce, payment or
 * SMS gateway): refused at save time with the same checks OutboundUrlGuard
 * applies when the call is made.
 */
class SafeOutboundUrl implements ValidationRule
{
    public function __construct(
        protected bool $requireHttps = true,
        /** A URL template: {placeholders} are replaced by a letter before the address is checked. */
        protected bool $template = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $url = $this->template ? (string) preg_replace('/\{[a-z_0-9]+\}/i', 'x', $value) : $value;

        if (($reason = OutboundUrlGuard::reason($url, $this->requireHttps)) !== null) {
            $fail($reason);
        }
    }
}
