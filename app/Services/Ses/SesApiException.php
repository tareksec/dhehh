<?php

namespace App\Services\Ses;

use Aws\Exception\AwsException;
use RuntimeException;

/**
 * An Amazon SES / SNS call that did not work, with a message the owner can act
 * on (the permission that is missing, the key that is wrong) instead of the raw
 * SDK text.
 */
class SesApiException extends RuntimeException
{
    public static function fromAws(\Throwable $exception): self
    {
        $code = $exception instanceof AwsException ? (string) $exception->getAwsErrorCode() : '';
        $raw = $exception instanceof AwsException
            ? ((string) $exception->getAwsErrorMessage() ?: $exception->getMessage())
            : $exception->getMessage();

        $message = match (true) {
            in_array($code, ['InvalidClientTokenId', 'UnrecognizedClientException'], true) => __('AWS does not recognise the access key ID. Copy it again from the AWS console (IAM → Users → Security credentials).'),
            $code === 'SignatureDoesNotMatch' => __('AWS refused the secret access key. Copy it again; it is shown only once when the key is created.'),
            in_array($code, ['AccessDenied', 'AccessDeniedException', 'AuthorizationError'], true) => __('This AWS key is not allowed to do that: :error. Add the permission in AWS IAM (the list is on the Amazon SES page).', ['error' => $raw]),
            $code === 'ExpiredToken' => __('The AWS key has expired.'),
            in_array($code, ['TooManyRequestsException', 'Throttling', 'ThrottlingException'], true) => __('AWS is asking us to slow down. Try again in a minute.'),
            $code === '' => __('Could not reach AWS: :error', ['error' => $raw]),
            default => $raw,
        };

        return new self($message, 0, $exception);
    }
}
