<?php

namespace App\Services\Social;

use RuntimeException;

/** A picture could not be made ready for a platform. The message is safe to show. */
class SocialMediaException extends RuntimeException {}
