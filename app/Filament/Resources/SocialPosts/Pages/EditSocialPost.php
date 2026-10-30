<?php

namespace App\Filament\Resources\SocialPosts\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\SocialPosts\SocialPostResource;
use App\Models\SocialPost;
use App\Services\AuditLogService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSocialPost extends EditRecord
{
    use HasStickyHeaderFormActions;

    protected static string $resource = SocialPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...array_map(
                fn ($action) => $action->record($this->getRecord())->after(fn () => $this->refreshFormData([
                    'status', 'approval_state', 'publish_state', 'publish_error', 'scheduled_at',
                ])),
                SocialPostResource::workflowActions(),
            ),
            ...(SocialPostResource::isReadOnly($this->getRecord()) ? [] : [$this->getStickySaveFormAction()]),
        ];
    }

    /** What is being published, or is finished, is never saved over. */
    protected function beforeSave(): void
    {
        if (SocialPostResource::isReadOnly($this->getRecord())) {
            Notification::make()
                ->title(__('This post cannot be changed now.'))
                ->body(__('It is being published, needs reconciling, or is finished.'))
                ->danger()
                ->send();

            $this->halt();
        }
    }

    /**
     * An approval covers one exact version. If the content was changed, the approval is voided right away, and a
     * scheduled post goes back to waiting (nothing is sent without a fresh approval).
     */
    protected function afterSave(): void
    {
        /** @var SocialPost $post */
        $post = $this->getRecord()->refresh();

        if (! $post->approvalIsStale()) {
            return;
        }

        $post->forceFill([
            'approval_state' => SocialPost::APPROVAL_PENDING,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approved_fingerprint' => null,
            'status' => $post->status === SocialPost::STATUS_SCHEDULED ? SocialPost::STATUS_READY : $post->status,
        ])->save();

        app(AuditLogService::class)->record('social_post.approval_voided', $post, null, ['number' => $post->number]);

        Notification::make()
            ->title(__('The post changed, so its approval was taken back.'))
            ->body(__('Approve it again before it can be published.'))
            ->warning()
            ->send();
    }
}
