<?php

namespace App\Services\VideoGeneration;

use RuntimeException;

/**
 * Raised by a provider adapter (or the resolver) when a submit or poll call
 * cannot be completed — bad config, an HTTP failure, or a provider-reported
 * failure. GenerateVideoJob catches this, marks the row `failed` with the
 * message, and notifies the requesting user.
 */
class VideoGenerationException extends RuntimeException
{
}
