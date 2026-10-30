<?php

namespace App\Services\Social;

use RuntimeException;

/** Another run owns the post now: this one must stop without writing anything. */
class SocialLeaseLostException extends RuntimeException {}
