<?php

namespace App\Services\Social;

use RuntimeException;

/** An action on a post that is not allowed right now. The message is safe and translated. */
class SocialPostException extends RuntimeException {}
