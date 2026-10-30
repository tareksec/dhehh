<?php

namespace App\Services\Social;

use App\Models\SocialPost;

/**
 * The private, per-platform checkpoint of one publish run (plan §8, rules 2 and 3).
 *
 * Every write is guarded by the run's lease token: if another run owns the post by now, nothing is written and
 * SocialLeaseLostException stops this run, so a stale retry can never overwrite a newer one.
 *
 * Per platform the checkpoint is:
 *   state      idle | running | succeeded | failed | uncertain
 *   completed  the last step that is known to have worked (e.g. `container`), or null
 *   pending    the external call that was started but not answered yet, or null
 *   container_id / post_id   ids the platform returned
 *   error      a message that is safe to show
 *   blocking   true when a person has to fix the connection first
 *
 * `uncertain` means a create call may have gone through although no answer came back. It is never replayed by
 * the job: a person checks the platform and reconciles.
 */
final class SocialPublishProgress
{
    public const STATE_IDLE = 'idle';

    public const STATE_RUNNING = 'running';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    public const STATE_UNCERTAIN = 'uncertain';

    /** @var array<string, array<string, mixed>> */
    private array $progress;

    /** @var array<string, array<string, mixed>> */
    private array $result;

    public function __construct(private readonly SocialPost $post, private readonly string $leaseToken)
    {
        $this->progress = (array) $post->publish_progress;
        $this->result = (array) $post->publish_result;
    }

    /** @return array<string, mixed> */
    public function get(string $platform): array
    {
        return array_merge([
            'state' => self::STATE_IDLE,
            'completed' => null,
            'pending' => null,
            'container_id' => null,
            'post_id' => null,
            'error' => null,
            'blocking' => false,
        ], (array) ($this->progress[$platform] ?? []));
    }

    public function state(string $platform): string
    {
        return (string) $this->get($platform)['state'];
    }

    /** Written BEFORE an external call that creates something. */
    public function begin(string $platform, string $call): void
    {
        $this->put($platform, ['state' => self::STATE_RUNNING, 'pending' => $call, 'error' => null, 'blocking' => false]);
    }

    /** The pending call was answered and worked; remember the step and any ids it returned. */
    public function completed(string $platform, string $step, array $extra = []): void
    {
        $this->put($platform, array_merge($extra, ['completed' => $step, 'pending' => null]));
    }

    public function succeed(string $platform, string $postId, ?string $link): void
    {
        $this->put($platform, [
            'state' => self::STATE_SUCCEEDED,
            'completed' => 'published',
            'pending' => null,
            'post_id' => $postId,
            'error' => null,
            'blocking' => false,
        ], [
            'status' => self::STATE_SUCCEEDED,
            'post_id' => $postId,
            'link' => $link,
            'at' => now()->toIso8601String(),
        ]);
    }

    /** The platform said no, clearly. Nothing was created by the failed call. */
    public function fail(string $platform, string $message, bool $blocking = false, array $extra = []): void
    {
        $this->put($platform, array_merge($extra, [
            'state' => self::STATE_FAILED,
            'pending' => null,
            'error' => $message,
            'blocking' => $blocking,
        ]), ['status' => self::STATE_FAILED, 'error' => $message, 'at' => now()->toIso8601String()]);
    }

    /** No clear answer: it may or may not exist on the platform. Never replayed automatically. */
    public function uncertain(string $platform, string $message): void
    {
        $this->put($platform, [
            'state' => self::STATE_UNCERTAIN,
            'error' => $message,
            'blocking' => false,
        ], ['status' => self::STATE_UNCERTAIN, 'error' => $message, 'at' => now()->toIso8601String()]);
    }

    /**
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>|null  $result
     */
    private function put(string $platform, array $progress, ?array $result = null): void
    {
        $this->progress[$platform] = array_merge($this->get($platform), $progress, ['updated_at' => now()->toIso8601String()]);

        if ($result !== null) {
            $this->result[$platform] = $result;
        }

        $this->persist();
    }

    private function persist(): void
    {
        $written = SocialPost::withoutGlobalScopes()
            ->whereKey($this->post->getKey())
            ->where('lease_token', $this->leaseToken)
            ->update([
                'publish_progress' => json_encode($this->progress, JSON_UNESCAPED_UNICODE),
                'publish_result' => json_encode($this->result, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

        if ($written === 0) {
            throw new SocialLeaseLostException('The publish lease for this post was lost.');
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->progress;
    }

    /** @return array<string, array<string, mixed>> */
    public function results(): array
    {
        return $this->result;
    }
}
