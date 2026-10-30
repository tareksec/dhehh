<?php

namespace App\Filament\Resources\SocialPosts\Pages;

use App\Filament\Resources\SocialPosts\SocialPostResource;
use App\Models\SocialPost;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSocialPosts extends ListRecords
{
    protected static string $resource = SocialPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** The tabs of plan §5: Draft, Waiting approval, Scheduled, Posted, Blocked, Archived. */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('All open'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', SocialPost::STATUS_ARCHIVED)),
            'draft' => Tab::make(__('Draft'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', SocialPost::STATUS_DRAFT)),
            'waiting' => Tab::make(__('Waiting approval'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SocialPost::STATUS_READY)
                    ->where('approval_state', SocialPost::APPROVAL_PENDING)),
            'approved' => Tab::make(__('Approved'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SocialPost::STATUS_READY)
                    ->where('approval_state', SocialPost::APPROVAL_APPROVED)),
            'scheduled' => Tab::make(__('Scheduled'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', SocialPost::STATUS_SCHEDULED)),
            'posted' => Tab::make(__('Posted'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', SocialPost::STATUS_POSTED)),
            'blocked' => Tab::make(__('Blocked'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', SocialPost::STATUS_BLOCKED)),
            'archived' => Tab::make(__('Archived'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', SocialPost::STATUS_ARCHIVED)),
        ];
    }
}
