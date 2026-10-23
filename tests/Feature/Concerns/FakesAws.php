<?php

namespace Tests\Feature\Concerns;

use App\Services\Ses\AwsClientFactory;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;

/**
 * Stands in for Amazon: the AWS SDK clients the SES pages use answer from a
 * queue (a mock handler), so no test ever calls AWS. Each step names the call
 * that is expected next, which keeps a test honest about what the code does.
 */
trait FakesAws
{
    /** @var array<int, array{name: string, args: array<string, mixed>}> every AWS call made, in order */
    protected array $awsCalls = [];

    protected ?MockHandler $awsHandler = null;

    /**
     * @param  array<int, array{0: string, 1: Result|\Throwable}>  $steps  [expected call name, its answer]
     */
    protected function fakeAws(array $steps): void
    {
        $this->awsCalls = [];
        $queue = [];

        foreach ($steps as [$name, $answer]) {
            $queue[] = function (CommandInterface $command) use ($name, $answer) {
                $this->awsCalls[] = ['name' => $command->getName(), 'args' => $command->toArray()];
                $this->assertSame($name, $command->getName(), 'AWS was asked for a different call than expected.');

                return $answer;
            };
        }

        $this->awsHandler = new MockHandler($queue);
        $handler = $this->awsHandler;

        $this->app->instance(AwsClientFactory::class, new class($handler) extends AwsClientFactory
        {
            public function __construct(private MockHandler $handler) {}

            protected function config(array $credentials): array
            {
                return parent::config($credentials) + ['handler' => $this->handler];
            }
        });
    }

    protected function awsError(string $code, string $message = 'Denied'): AwsException
    {
        return new AwsException($message, new Command('Fake'), ['code' => $code, 'message' => $message]);
    }

    /** @param  array<string, mixed>  $data */
    protected function awsResult(array $data = []): Result
    {
        return new Result($data);
    }

    protected function assertAllAwsCallsMade(): void
    {
        $this->assertSame(0, $this->awsHandler?->count() ?? 0, 'Some expected AWS calls were never made.');
    }
}
