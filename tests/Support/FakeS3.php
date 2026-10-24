<?php

namespace Tests\Support;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use DateTimeImmutable;
use DateTimeInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

/**
 * An S3 bucket in memory: a stand-in for Cloudflare R2 that answers the calls the backup
 * code makes (put, multipart, head, get, list, delete). Nothing leaves the process.
 *
 * `$fail` lets a test make a call fail: a callable (CommandInterface, int $nth) => ?string AWS
 * error code, called before the call is handled; returning a code makes the call fail with it.
 */
class FakeS3
{
    /** @var array<string, string> key => bytes */
    public array $objects = [];

    /** @var array<string, DateTimeInterface> key => last modified */
    public array $modified = [];

    /** @var array<int, array{0: string, 1: array<string, mixed>}> every call, in order */
    public array $calls = [];

    /** @var array<string, array<int, string>> upload id => parts by number */
    public array $uploads = [];

    /** @var ?callable */
    public $fail = null;

    /** Pretend the stored object has this many bytes less than was sent (a damaged upload). */
    public int $shortBy = 0;

    private int $counter = 0;

    public function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => function (CommandInterface $command) {
                // A failed call is a rejected promise, as the SDK's own handlers return it.
                try {
                    return Create::promiseFor($this->handle($command));
                } catch (AwsException $exception) {
                    return Create::rejectionFor($exception);
                }
            },
        ]);
    }

    public function count(string $operation): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call[0] === $operation));
    }

    /** Put an object in directly, as if an earlier backup had left it. */
    public function put(string $key, string $bytes = 'x', ?DateTimeInterface $at = null): void
    {
        $this->objects[$key] = $bytes;
        $this->modified[$key] = $at ?? new DateTimeImmutable;
    }

    protected function handle(CommandInterface $command): Result
    {
        $name = $command->getName();
        $this->calls[] = [$name, $command->toArray()];
        $nth = $this->count($name);

        if ($this->fail !== null && ($code = ($this->fail)($command, $nth)) !== null) {
            throw new AwsException("{$code} (fake)", $command, ['code' => $code], null);
        }

        return match ($name) {
            'PutObject' => $this->putObject($command),
            'CreateMultipartUpload' => $this->createUpload(),
            'UploadPart' => $this->uploadPart($command),
            'CompleteMultipartUpload' => $this->complete($command),
            'AbortMultipartUpload' => $this->abort($command),
            'HeadObject' => $this->head($command),
            'GetObject' => $this->get($command),
            'DeleteObject' => $this->delete($command),
            'ListObjectsV2' => $this->list($command),
            default => new Result([]),
        };
    }

    protected function putObject(CommandInterface $command): Result
    {
        $body = $command['Body'] ?? null;
        $source = $command['SourceFile'] ?? null;
        $bytes = $source !== null ? (string) file_get_contents($source) : (is_object($body) ? (string) $body : (string) $body);

        $this->put((string) $command['Key'], $bytes);

        return new Result(['ETag' => '"'.md5($bytes).'"']);
    }

    protected function createUpload(): Result
    {
        $id = 'upload-'.(++$this->counter);
        $this->uploads[$id] = [];

        return new Result(['UploadId' => $id]);
    }

    protected function uploadPart(CommandInterface $command): Result
    {
        $body = $command['Body'];
        $body->rewind();
        $this->uploads[(string) $command['UploadId']][(int) $command['PartNumber']] = (string) $body;

        return new Result(['ETag' => '"part-'.$command['PartNumber'].'"']);
    }

    protected function complete(CommandInterface $command): Result
    {
        $id = (string) $command['UploadId'];
        $parts = $this->uploads[$id] ?? [];
        ksort($parts);
        $this->put((string) $command['Key'], implode('', $parts));
        unset($this->uploads[$id]);

        return new Result(['ETag' => '"done"']);
    }

    protected function abort(CommandInterface $command): Result
    {
        unset($this->uploads[(string) $command['UploadId']]);

        return new Result([]);
    }

    protected function head(CommandInterface $command): Result
    {
        $key = (string) $command['Key'];

        if (! isset($this->objects[$key])) {
            throw new AwsException('NotFound', $command, ['code' => 'NotFound', 'response' => new Response(404)]);
        }

        return new Result(['ContentLength' => strlen($this->objects[$key]) - $this->shortBy]);
    }

    protected function get(CommandInterface $command): Result
    {
        $key = (string) $command['Key'];

        if (! isset($this->objects[$key])) {
            throw new AwsException('NoSuchKey', $command, ['code' => 'NoSuchKey', 'response' => new Response(404)]);
        }

        // The client turns SaveAs into an http sink before the handler sees the command.
        $sink = $command['@http']['sink'] ?? $command['SaveAs'] ?? null;

        if (is_string($sink)) {
            file_put_contents($sink, $this->objects[$key]);
        }

        return new Result(['Body' => Utils::streamFor($this->objects[$key])]);
    }

    protected function delete(CommandInterface $command): Result
    {
        unset($this->objects[(string) $command['Key']], $this->modified[(string) $command['Key']]);

        return new Result([]);
    }

    protected function list(CommandInterface $command): Result
    {
        $prefix = (string) ($command['Prefix'] ?? '');
        $delimiter = (string) ($command['Delimiter'] ?? '');
        $keys = array_values(array_filter(
            array_keys($this->objects),
            fn (string $key): bool => str_starts_with($key, $prefix)
                && ($delimiter === '' || ! str_contains(substr($key, strlen($prefix)), $delimiter)),
        ));
        sort($keys);

        // Two keys per page, so paging is exercised whenever there are more.
        $pageSize = min(2, (int) ($command['MaxKeys'] ?? 2));
        $start = (int) ($command['ContinuationToken'] ?? 0);
        $page = array_slice($keys, $start, $pageSize);
        $more = $start + $pageSize < count($keys);

        return new Result([
            'Contents' => array_map(fn (string $key): array => [
                'Key' => $key,
                'Size' => strlen($this->objects[$key]),
                'LastModified' => $this->modified[$key] ?? new DateTimeImmutable,
            ], $page),
            'IsTruncated' => $more,
            'NextContinuationToken' => $more ? (string) ($start + $pageSize) : null,
            'KeyCount' => count($page),
        ]);
    }
}
