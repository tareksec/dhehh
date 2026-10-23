<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * A restore that failed after it began to change the database. Says what state it left:
 * $rolledBack is true when the previous database was put back from the safety copy, false when
 * that failed too (the database may be half loaded), and null when there was no safety copy.
 */
class BackupRestoreException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?bool $rolledBack = null,
        public readonly ?string $safetyFile = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
