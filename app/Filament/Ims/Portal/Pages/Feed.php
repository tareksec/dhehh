<?php

namespace App\Filament\Ims\Portal\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Ims\Feed\FeedService;
use App\Models\FeedComment;
use App\Models\FeedPost;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The company feed in the portal (IMS-V2/PRD.md FR-63): posts, pictures,
 * likes and comments. Other investors' comments show without a name.
 */
class Feed extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = 'holder';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.ims.portal.feed';

    /** @var array<int, string> post id => comment being written */
    public array $drafts = [];

    public static function getNavigationGroup(): ?string
    {
        return __('Overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('Feed');
    }

    public function getTitle(): string
    {
        return __('Feed');
    }

    /** @return Collection<int, FeedPost> */
    public function posts(): Collection
    {
        $posts = FeedPost::query()->published()
            ->with(['images', 'comments' => fn ($query) => $query->shown()->oldest()])
            ->withCount('likes')
            ->orderByDesc('is_pinned')->latest('published_at')->limit(30)->get();

        if (! $this->isStaffPreview()) {
            $posts->each(fn (FeedPost $post) => app(FeedService::class)->seen($post, $this->account()));
        }

        return $posts;
    }

    public function likedByMe(FeedPost $post): bool
    {
        return $post->likes()->where('investor_portal_account_id', $this->account()->getKey())->exists();
    }

    public function isMine(FeedComment $comment): bool
    {
        return (int) $comment->investor_portal_account_id === (int) $this->account()->getKey();
    }

    public function like(int $postId): void
    {
        $this->refuseInStaffPreview();
        $this->guarded(fn () => app(FeedService::class)->toggleLike(FeedPost::query()->published()->findOrFail($postId), $this->account()));
    }

    public function comment(int $postId): void
    {
        $this->refuseInStaffPreview();
        $this->guarded(function () use ($postId): void {
            app(FeedService::class)->comment(FeedPost::query()->published()->findOrFail($postId), $this->account(), (string) ($this->drafts[$postId] ?? ''));
            $this->drafts[$postId] = '';
        });
    }

    public function deleteComment(int $commentId): void
    {
        $this->refuseInStaffPreview();
        $this->guarded(fn () => app(FeedService::class)->deleteOwn(FeedComment::query()->findOrFail($commentId), $this->account()));
    }

    private function guarded(callable $work): void
    {
        try {
            $work();
        } catch (ValidationException $exception) {
            Notification::make()->title((string) collect($exception->errors())->flatten()->first())->danger()->send();
        }
    }
}
