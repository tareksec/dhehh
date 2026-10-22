<?php

namespace App\Filament\Ims\Office\Resources\SupportThreads;

use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Ims\Office\Resources\SupportThreads\Pages\ListSupportThreads;
use App\Filament\Ims\Office\Resources\SupportThreads\Pages\ViewSupportThread;
use App\Models\SupportThread;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Investors' questions (IMS-V2/PRD.md FR-64): unanswered first. */
class SupportThreadResource extends Resource
{
    use HasGuardedNavigationBadge;

    protected static ?string $model = SupportThread::class;

    protected static ?string $slug = 'support';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('question');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Support');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('ims.support.reply') || $user?->hasPermission('investments.view')) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function navigationBadge(): ?string
    {
        $count = SupportThread::query()->where('status', SupportThread::STATUS_OPEN)->where('staff_unread', true)->count();

        return $count > 0 ? ImsNumbers::count($count) : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['investor', 'project'])->orderByDesc('staff_unread')->latest('last_message_at'))
            ->columns([
                IconColumn::make('staff_unread')->label(__('New'))->boolean()->trueIcon('heroicon-s-envelope')->falseIcon('heroicon-o-envelope-open'),
                TextColumn::make('investor.name')->label(__('Investor'))->searchable()->wrap(),
                TextColumn::make('subject')->label(__('Subject'))->wrap()->searchable(),
                TextColumn::make('project.name')->label(__('Project'))->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === SupportThread::STATUS_OPEN ? __('Open') : __('Closed'))
                    ->color(fn (string $state): string => $state === SupportThread::STATUS_OPEN ? 'warning' : 'gray'),
                TextColumn::make('last_message_at')->label(__('Last message'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([SupportThread::STATUS_OPEN => __('Open'), SupportThread::STATUS_CLOSED => __('Closed')])->default(SupportThread::STATUS_OPEN),
            ])
            ->recordActions([ViewAction::make()->label(__('Open'))])
            ->emptyStateHeading(__('No question is waiting'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportThreads::route('/'),
            'view' => ViewSupportThread::route('/{record}'),
        ];
    }
}
