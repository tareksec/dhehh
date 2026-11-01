<?php

namespace App\Support;

use RuntimeException;

/** An outbound address the server refuses to call (see OutboundUrlGuard). */
class UnsafeOutboundUrl extends RuntimeException {}
