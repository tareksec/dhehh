<?php

namespace App\Services\Meta;

use RuntimeException;

class MetaGraphException extends RuntimeException
{
    /**
     * @param  bool  $responseReceived  false when the call may have reached Meta but no answer came back
     *                                  (timeout, dropped connection). For a call that creates something
     *                                  that is not the same as "Meta said no": see outcomeIsUnknown().
     */
    public function __construct(
        string $message,
        public readonly ?int $graphCode = null,
        public readonly ?int $httpStatus = null,
        public readonly bool $responseReceived = true,
    ) {
        parent::__construct($message);
    }

    /**
     * A bad or expired token, or a missing permission. Retrying cannot help; a person has to fix the
     * connection first, so a post that hits this is blocked, not retried.
     */
    public function blocksPosting(): bool
    {
        return in_array($this->graphCode, [102, 190, 10], true)
            || ($this->graphCode !== null && $this->graphCode >= 200 && $this->graphCode <= 299);
    }

    /**
     * Whether a create call may have succeeded although we did not get a clear answer: nothing came
     * back, or Meta answered with a server error. Such a call is never replayed automatically.
     */
    public function outcomeIsUnknown(): bool
    {
        return ! $this->responseReceived || ($this->httpStatus !== null && $this->httpStatus >= 500);
    }
}
