<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\AiTools;
use App\Filament\Pages\Concerns\SavesVideosToDrive;
use App\Models\GeneratedVideo;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoGovernanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §10 / Phase 4 — the searchable
 * video library, mirroring ImageLibrary where the concept exists: every
 * video this company has generated, filterable by context / status / who
 * made it / review / favourites / attached / saved to Google Drive. "Reuse"
 * opens Video Generation prefilled from a row (VideoGeneration::mount()).
 *
 * Clips play from the company's own public storage (R2 or local). "Save to
 * Google Drive" only sends a copy (SaveVideoToDriveJob).
 *
 * Same permission as the tool (`ai_tools.video_generation`). A user who also
 * holds `ai_tools.video_generation.review` sees Approve / Reject on videos a
 * company's governance rules left "pending review" (Phase 5). Company
 * isolation is the model's own CompanyScope; nothing here widens it.
 */
class VideoLibrary extends Page implements HasTable
{
    use InteractsWithTable;
    use SavesVideosToDrive;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $cluster = AiTools::class;

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.video-library';

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('ai_tools.video_generation') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Video Library');
    }

    public function getTitle(): string
    {
        return __('Video Library');
    }

    public function hasSelectedCompany(): bool
    {
        $context = app(CompanyContext::class);

        return $context->hasCompany() && ! $context->isAllCompanies();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->baseQuery())
            ->defaultSort('created_at', 'desc')
            ->poll(fn (): ?string => $this->hasWorkInFlight() ? '5s' : null)
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->state(fn (GeneratedVideo $record): ?string => $record->thumbnailUrl())
                    ->square()
                    ->size(56),
                TextColumn::make('prompt')
                    ->label(__('Prompt'))
                    ->wrap()
                    ->lineClamp(2)
                    ->limit(180)
                    ->searchable(['prompt', 'original_prompt'])
                    ->description(fn (GeneratedVideo $record): ?string => $record->original_prompt
                        ? __('Enhanced from: :prompt', ['prompt' => Str::limit($record->original_prompt, 90)])
                        : null),
                TextColumn::make('context')
                    ->label(__('Context'))
                    ->badge()
                    ->formatStateUsing(fn (GeneratedVideo $record): string => $record->contextLabel())
                    ->color('gray'),
                TextColumn::make('duration')
                    ->label(__('Duration'))
                    ->state(fn (GeneratedVideo $record): string => $record->durationLabel().' · '.$record->aspect_ratio)
                    ->toggleable(),
                TextColumn::make('provider_label')
                    ->label(__('Provider'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('user.name')
                    ->label(__('By'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('attached_to')
                    ->label(__('Attached to'))
                    ->state(fn (GeneratedVideo $record): ?string => $record->linkedLabel())
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_favorite')
                    ->label(__('Favourite'))
                    ->boolean()
                    ->trueIcon(Heroicon::Star)
                    ->falseIcon(Heroicon::OutlinedStar)
                    ->sortable(),
                TextColumn::make('drive_status')
                    ->label(__('Google Drive'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        GeneratedVideo::DRIVE_SAVED => __('Saved'),
                        GeneratedVideo::DRIVE_SAVING => __('Saving…'),
                        GeneratedVideo::DRIVE_FAILED => __('Failed'),
                        default => '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        GeneratedVideo::DRIVE_SAVED => 'success',
                        GeneratedVideo::DRIVE_SAVING => 'warning',
                        GeneratedVideo::DRIVE_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (GeneratedVideo $record): ?string => $record->drive_status === GeneratedVideo::DRIVE_FAILED ? $record->drive_error : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('review_status')
                    ->label(__('Review'))
                    ->badge()
                    ->formatStateUsing(fn (GeneratedVideo $record): string => $record->reviewStatusLabel())
                    ->color(fn (?string $state): string => match ($state) {
                        GeneratedVideo::REVIEW_PENDING => 'warning',
                        GeneratedVideo::REVIEW_REJECTED => 'danger',
                        GeneratedVideo::REVIEW_APPROVED => 'success',
                        default => 'gray',
                    })
                    ->description(fn (GeneratedVideo $record): ?string => $record->review_note ? Str::limit($record->review_note, 80) : null)
                    ->toggleable(),
                TextColumn::make('estimated_cost')
                    ->label(__('Est. cost'))
                    ->numeric(decimalPlaces: 4)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        GeneratedVideo::STATUS_COMPLETED => 'success',
                        GeneratedVideo::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('context')
                    ->label(__('Context'))
                    ->options(GeneratedVideo::CONTEXTS),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        GeneratedVideo::STATUS_COMPLETED => __('Completed'),
                        GeneratedVideo::STATUS_PROCESSING => __('Processing'),
                        GeneratedVideo::STATUS_QUEUED => __('Queued'),
                        GeneratedVideo::STATUS_FAILED => __('Failed'),
                    ]),
                SelectFilter::make('user_id')
                    ->label(__('Created by'))
                    ->options(fn (): array => $this->creatorOptions()),
                SelectFilter::make('review_status')
                    ->label(__('Review'))
                    ->options(GeneratedVideo::REVIEW_STATUSES),
                TernaryFilter::make('is_favorite')
                    ->label(__('Favourites')),
                TernaryFilter::make('attached')
                    ->label(__('Attached to a record'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('linked_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('linked_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TernaryFilter::make('saved_to_drive')
                    ->label(__('Saved to Google Drive'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('drive_status', GeneratedVideo::DRIVE_SAVED),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q
                            ->whereNull('drive_status')
                            ->orWhere('drive_status', '!=', GeneratedVideo::DRIVE_SAVED)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('play')
                    ->label(__('Play'))
                    ->icon(Heroicon::OutlinedPlay)
                    ->visible(fn (GeneratedVideo $record): bool => $record->hasPlayableVideo())
                    ->modalHeading(fn (GeneratedVideo $record): string => Str::limit((string) $record->prompt, 80) ?: __('Video'))
                    ->modalContent(fn (GeneratedVideo $record) => view('filament.pages.partials.video-player', ['video' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                Action::make('reusePrompt')
                    ->label(__('Reuse'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->url(fn (GeneratedVideo $record): string => VideoGeneration::getUrl(['from' => $record->getKey()])),
                Action::make('toggleFavorite')
                    ->label(fn (GeneratedVideo $record): string => $record->is_favorite ? __('Remove favourite') : __('Add to favourites'))
                    ->icon(fn (GeneratedVideo $record): string => $record->is_favorite ? 'heroicon-o-star' : 'heroicon-s-star')
                    ->action(function (GeneratedVideo $record): void {
                        $record->forceFill(['is_favorite' => ! $record->is_favorite])->save();

                        Notification::make()
                            ->title($record->is_favorite ? __('Added to favourites') : __('Removed from favourites'))
                            ->success()
                            ->send();
                    }),
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (GeneratedVideo $record): bool => $record->hasPlayableVideo())
                    ->url(fn (GeneratedVideo $record): ?string => $record->outputUrl())
                    ->openUrlInNewTab(),
                Action::make('saveToDrive')
                    ->label(fn (GeneratedVideo $record): string => $record->drive_status === GeneratedVideo::DRIVE_FAILED
                        ? __('Retry Google Drive')
                        : __('Save to Google Drive'))
                    ->icon(Heroicon::OutlinedCloudArrowUp)
                    ->visible(fn (GeneratedVideo $record): bool => $record->hasPlayableVideo()
                        && ! $record->isSavedToDrive()
                        && ! $record->isSavingToDrive()
                        && $this->driveAvailable())
                    ->action(fn (GeneratedVideo $record) => $this->queueDriveSave($record)),
                Action::make('openInDrive')
                    ->label(__('Open in Google Drive'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn (GeneratedVideo $record): bool => $record->isSavedToDrive())
                    ->url(fn (GeneratedVideo $record): ?string => $record->drive_url)
                    ->openUrlInNewTab(),
                Action::make('approveReview')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (GeneratedVideo $record): bool => $this->canReview() && $record->review_status === GeneratedVideo::REVIEW_PENDING)
                    ->requiresConfirmation()
                    ->modalDescription(__('Approve this video so its creator can put it on a product or offer.'))
                    ->action(function (GeneratedVideo $record): void {
                        app(VideoGovernanceService::class)->review($record, Auth::user(), approved: true);

                        Notification::make()->title(__('Video approved'))->success()->send();
                    }),
                Action::make('rejectReview')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (GeneratedVideo $record): bool => $this->canReview() && $record->review_status === GeneratedVideo::REVIEW_PENDING)
                    ->schema([
                        Textarea::make('note')
                            ->label(__('Reason (shown to the creator)'))
                            ->rows(3)
                            ->maxLength(500),
                    ])
                    ->action(function (GeneratedVideo $record, array $data): void {
                        app(VideoGovernanceService::class)->review($record, Auth::user(), approved: false, note: $data['note'] ?? null);

                        Notification::make()->title(__('Video rejected'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No videos yet'))
            ->emptyStateDescription(__('Videos you create in the Video Generation tool are collected here.'))
            ->emptyStateIcon(Heroicon::OutlinedFilm);
    }

    public function canReview(): bool
    {
        return Auth::user()?->hasPermission('ai_tools.video_generation.review') ?? false;
    }

    protected function baseQuery(): Builder
    {
        $query = GeneratedVideo::query()
            ->where('tool', GeneratedVideo::TOOL_VIDEO_GENERATION)
            ->with(['user', 'linked']);

        // The blade already hides the table unless one company is selected;
        // this keeps an all-companies super admin from ever reading across
        // companies even if the table renders.
        return $this->hasSelectedCompany() ? $query : $query->whereRaw('1 = 0');
    }

    /** A video still generating, or a Drive copy still uploading — keep the table fresh. */
    protected function hasWorkInFlight(): bool
    {
        if (! $this->hasSelectedCompany()) {
            return false;
        }

        return $this->baseQuery()
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('status', [GeneratedVideo::STATUS_QUEUED, GeneratedVideo::STATUS_PROCESSING])
                ->orWhere('drive_status', GeneratedVideo::DRIVE_SAVING))
            ->exists();
    }

    /** @return array<int, string> */
    protected function creatorOptions(): array
    {
        if (! $this->hasSelectedCompany()) {
            return [];
        }

        $userIds = GeneratedVideo::query()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
