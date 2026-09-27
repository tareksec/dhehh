<?php

namespace App\Services\VideoGeneration;

use RuntimeException;

/**
 * Raised by GeneratedVideoAttacher when a finished video cannot be attached
 * to a target record — the generation is not finished or not cleared by a
 * reviewer, its stored clip is gone, or the target belongs to another
 * company. The VideoGeneration page catches this and shows the message as a
 * notification.
 */
class VideoAttachmentException extends RuntimeException {}
