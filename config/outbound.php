<?php

/*
|--------------------------------------------------------------------------
| Outbound HTTP safety (SSRF)
|--------------------------------------------------------------------------
|
| Addresses an admin types into Integrations (website webhook, WooCommerce,
| payment gateway, SMS gateway) are called by the server, so they are checked
| by App\Support\OutboundUrlGuard before any request is made: no private,
| loopback, link-local or reserved network, and no redirect to one.
|
| Leave both settings alone in production. For a development machine that
| really talks to a service on the same network set OUTBOUND_ALLOW_PRIVATE_HOSTS=true,
| or list single host names in OUTBOUND_ALLOWED_HOSTS (comma separated).
*/

return [
    'allow_private_hosts' => (bool) env('OUTBOUND_ALLOW_PRIVATE_HOSTS', false),

    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('OUTBOUND_ALLOWED_HOSTS', ''))))),
];
