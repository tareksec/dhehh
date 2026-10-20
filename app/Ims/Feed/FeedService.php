<?php

namespace App\Ims\Feed;

use App\Ims\Files\ImsFiles;
use App\Ims\Notifications\ImsNotifier;
use App\Models\Company;
use App\Models\FeedComment;
use App\Models\FeedPost;
use App\Models\FeedPostImage;
use App\Models\FeedPostLike;
use App\Models\FeedPostView;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The investors' feed (IMS-V2/PRD.md FR-63, old IMS §10): company posts with
 * up to six pictures, likes and comments from investors. Other investors see
 * a comment without a name; the writer can delete their own; the office can
 * hide any and sees who saw, liked and commented. Limits: 10 comments and 60
 * likes per five minutes per person.
 */
class FeedService
{
    public function __construct(private ImsFiles $files, private ImsNotifier $notifier, private AuditLogService $audit) {}

    /**
     * @param  array{kind?: string, title?: string|null, body: string, link_url?: string|null, is_pinned?: bool, comments_enabled?: bool}  $data
     * @param  list<UploadedFile|string>  $images
     */
    public function post(Company $company, array $data, array $images, User $by): FeedPost
    {
        if (! $by->hasPermission('ims.feed.write')) {
            throw ValidationException::withMessages(['body' => __('You do not have permission to post to the feed.')]);
        }

        if (count($images) > FeedPost::MAX_IMAGES) {
            throw ValidationException::withMessages(['images' => __('Add at most :count pictures.', ['count' => FeedPost::MAX_IMAGES])]);
        }

        $post = DB::transaction(function () use ($company, $data, $images, $by): FeedPost {
            $post = FeedPost::query()->create([
                'company_id' => $company->getKey(),
                'kind' => array_key_exists($data['kind'] ?? 'post', FeedPost::KINDS) ? ($data['kind'] ?? 'post') : 'post',
                'title' => $data['title'] ?? null,
                'body' => trim($data['body']),
                'link_url' => $data['link_url'] ?? null,
                'is_pinned' => (bool) ($data['is_pinned'] ?? false),
                'comments_enabled' => (bool) ($data['comments_enabled'] ?? true),
                'published_at' => now(),
                'created_by' => $by->getKey(),
            ]);

            foreach (array_values($images) as $sort => $image) {
                FeedPostImage::query()->create([
                    'company_id' => $company->getKey(),
                    'post_id' => $post->getKey(),
                    'path' => $this->files->store($company, 'feed', $image, ImsFiles::IMAGES, 'images')['path'],
                    'sort' => $sort,
                ]);
            }

            $this->audit->record('ims.feed_posted', $post, null, ['by' => $by->getKey()]);

            return $post;
        });

        $title = (string) ($post->title ?: mb_strimwidth($post->body, 0, 80, '…'));
        Investor::query()->where('company_id', $company->getKey())->whereHas('portalAccount')->get()
            ->each(fn (Investor $investor) => $this->notifier->send('feed_post', $investor, ['title' => $title]));

        return $post;
    }

    public function seen(FeedPost $post, InvestorPortalAccount $account): void
    {
        FeedPostView::query()->firstOrCreate(
            ['post_id' => $post->getKey(), 'investor_portal_account_id' => $account->getKey()],
            ['company_id' => $post->company_id, 'viewed_at' => now()],
        );
    }

    /** Likes or un-likes; returns whether the post is now liked. */
    public function toggleLike(FeedPost $post, InvestorPortalAccount $account): bool
    {
        $this->limit('ims-feed-like:'.$account->getKey(), 60);

        $like = FeedPostLike::query()->where('post_id', $post->getKey())->where('investor_portal_account_id', $account->getKey())->first();

        if ($like) {
            $like->delete();

            return false;
        }

        FeedPostLike::query()->create(['company_id' => $post->company_id, 'post_id' => $post->getKey(), 'investor_portal_account_id' => $account->getKey()]);

        return true;
    }

    public function comment(FeedPost $post, InvestorPortalAccount $account, string $body): FeedComment
    {
        if (! $post->comments_enabled) {
            throw ValidationException::withMessages(['body' => __('Comments are closed on this post.')]);
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 1000) {
            throw ValidationException::withMessages(['body' => __('Write a comment of up to 1000 letters.')]);
        }

        $this->limit('ims-feed-comment:'.$account->getKey(), 10);

        return FeedComment::query()->create([
            'company_id' => $post->company_id,
            'post_id' => $post->getKey(),
            'investor_portal_account_id' => $account->getKey(),
            'body' => $body,
        ]);
    }

    /** The writer deletes their own comment. */
    public function deleteOwn(FeedComment $comment, InvestorPortalAccount $account): void
    {
        if ((int) $comment->investor_portal_account_id !== (int) $account->getKey()) {
            throw ValidationException::withMessages(['comment' => __('You can delete only your own comments.')]);
        }

        $comment->delete();
    }

    public function hide(FeedComment $comment, User $by): void
    {
        if (! $by->hasPermission('ims.feed.moderate')) {
            throw ValidationException::withMessages(['comment' => __('You do not have permission to moderate the feed.')]);
        }

        $comment->forceFill(['hidden_at' => now(), 'hidden_by' => $by->getKey()])->save();
        $this->audit->record('ims.feed_comment_hidden', $comment, null, ['by' => $by->getKey()]);
    }

    private function limit(string $key, int $perFiveMinutes): void
    {
        if (RateLimiter::tooManyAttempts($key, $perFiveMinutes)) {
            throw ValidationException::withMessages(['body' => __('Slow down a little and try again in a few minutes.')]);
        }

        RateLimiter::hit($key, 300);
    }
}
