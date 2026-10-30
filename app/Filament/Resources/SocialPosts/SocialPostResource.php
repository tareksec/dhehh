<?php

namespace App\Filament\Resources\SocialPosts;

use App\Filament\Clusters\Social;
use App\Filament\Resources\SocialPosts\Pages\CreateSocialPost;
use App\Filament\Resources\SocialPosts\Pages\EditSocialPost;
use App\Filament\Resources\SocialPosts\Pages\ListSocialPosts;
use App\Models\Company;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Social\SocialPostApprovalService;
use App\Services\Social\SocialPostException;
use App\Services\Social\SocialPostPlanner;
use App\Services\Social\SocialPostPublishingService;
use App\Support\CompanyMedia;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;

/**
 * Social posts: write, approve, schedule and publish to Facebook and Instagram
 * (19_SOCIAL_CONTENT_PLANNER_PLAN.md §5). Every state change goes through the services in App\Services\Social, which check
 * the permission and the rules themselves; this class only draws the screens and turns a refusal into a notification.
 */
class SocialPostResource extends Resource
{
    protected static ?string $model = SocialPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $cluster = Social::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('Posts');
    }

    public static function getModelLabel(): string
    {
        return __('Social post');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Social posts');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->disabled(fn (?SocialPost $record): bool => $record !== null && static::isReadOnly($record))
            ->components([
                Section::make(__('Post'))
                    ->columnSpanFull()
                    ->schema([
                        Select::make('company_id')
                            ->label(__('Company'))
                            ->options(fn (): array => Company::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                            ->visible(fn (?SocialPost $record): bool => $record === null && app(CompanyContext::class)->isAllCompanies())
                            ->helperText(__('Select the company that will own this post.')),
                        TextInput::make('title')
                            ->label(__('Title (for your own reference)'))
                            ->maxLength(255),
                        Select::make('language')
                            ->label(__('Language of the post'))
                            ->options(fn (): array => collect(SocialPost::LANGUAGES)->map(fn (string $label): string => __($label))->all())
                            ->default('bn')
                            ->required()
                            ->native(false),
                        Textarea::make('body')
                            ->label(__('Text'))
                            ->rows(8)
                            ->maxLength(SocialPostPlanner::FACEBOOK_TEXT_LIMIT)
                            ->helperText(__('Instagram captions can be 2,200 characters at most. The text is sent exactly as written.'))
                            ->columnSpanFull(),
                        CheckboxList::make('platforms')
                            ->label(__('Post to'))
                            ->options(fn (): array => collect(SocialPost::PLATFORMS)->map(fn (string $label): string => __($label))->all())
                            ->default([SocialPost::PLATFORM_FACEBOOK])
                            ->required()
                            ->columns(2),
                        Repeater::make('media')
                            ->label(__('Picture'))
                            ->relationship('media')
                            ->maxItems(1)
                            ->defaultItems(0)
                            ->addActionLabel(__('Attach a picture'))
                            ->helperText(__('One picture per post for now. Instagram needs one; Facebook can post text only.'))
                            ->schema([
                                FileUpload::make('path')
                                    ->label(__('Picture'))
                                    ->image()
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->maxSize(8192)
                                    ->required()
                                    ->disk(fn (): string => CompanyMedia::publicDiskName())
                                    ->directory(fn (): string => CompanyMedia::publicDirectory('social-posts'))
                                    ->fetchFileInformation(false)
                                    ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                                    ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                                    ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                                    ->openable()
                                    ->downloadable(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Publishing'))
                    ->columnSpanFull()
                    ->visible(fn (?SocialPost $record): bool => $record !== null)
                    ->schema([
                        View::make('filament.social.post-result')
                            ->viewData(fn (SocialPost $record): array => ['post' => $record])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('#')
                    ->formatStateUsing(fn (int $state): string => 'S-'.$state)
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('Post'))
                    ->getStateUsing(fn (SocialPost $record): string => filled($record->title) ? $record->title : Str::limit((string) $record->body, 60))
                    ->description(fn (SocialPost $record): ?string => $record->is_ai_drafted ? __('AI draft') : null)
                    ->wrap()
                    ->searchable(['title', 'body']),
                TextColumn::make('platforms')
                    ->label(__('Platforms'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __(SocialPost::PLATFORMS[$state] ?? ucfirst($state))),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __(SocialPost::STATUSES[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        SocialPost::STATUS_POSTED => 'success',
                        SocialPost::STATUS_SCHEDULED => 'info',
                        SocialPost::STATUS_READY => 'warning',
                        SocialPost::STATUS_BLOCKED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('approval_state')
                    ->label(__('Approval'))
                    ->badge()
                    ->getStateUsing(fn (SocialPost $record): string => $record->isApproved() ? 'approved' : ($record->approvalIsStale() ? 'stale' : 'pending'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => __('Approved'),
                        'stale' => __('Changed after approval'),
                        default => __('Not approved'),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'stale' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('publish_state')
                    ->label(__('Publishing'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, SocialPost $record): string => match (true) {
                        $record->hasUncertainPlatform() => __('Needs checking'),
                        $state === SocialPost::PUBLISH_QUEUED => __('Queued'),
                        $state === SocialPost::PUBLISH_RUNNING => __('Publishing'),
                        $state === SocialPost::PUBLISH_SUCCEEDED => __('Done'),
                        $state === SocialPost::PUBLISH_FAILED => __('Failed'),
                        default => '-',
                    })
                    ->color(fn (string $state, SocialPost $record): string => match (true) {
                        $record->hasUncertainPlatform() => 'warning',
                        $state === SocialPost::PUBLISH_SUCCEEDED => 'success',
                        $state === SocialPost::PUBLISH_FAILED => 'danger',
                        in_array($state, [SocialPost::PUBLISH_QUEUED, SocialPost::PUBLISH_RUNNING], true) => 'info',
                        default => 'gray',
                    })
                    ->tooltip(fn (SocialPost $record): ?string => $record->publish_error),
                TextColumn::make('scheduled_at')
                    ->label(__('Scheduled for'))
                    ->formatStateUsing(fn (mixed $state, SocialPost $record): ?string => $state
                        ? $record->scheduled_at->copy()->setTimezone($record->timezone())->format('d M Y, h:i A')
                        : null)
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->recordActions(static::workflowActions());
    }

    /**
     * The workflow buttons, shared by the list rows and the edit page header. Each one only calls a service; the
     * service decides whether the action is allowed and says why not.
     *
     * @return array<int, Action>
     */
    public static function workflowActions(): array
    {
        return [
            Action::make('sendForApproval')
                ->label(__('Send for approval'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (SocialPost $record): bool => in_array($record->status, [SocialPost::STATUS_DRAFT, SocialPost::STATUS_BLOCKED], true) && static::userCan('social.create'))
                ->action(fn (SocialPost $record) => static::runAction(fn () => app(SocialPostApprovalService::class)->submit($record, static::actor()), __('Sent for approval.'))),

            Action::make('approve')
                ->label(__('Approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('Once approved, this exact text and picture can be published. Any later change will need a new approval.'))
                ->visible(fn (SocialPost $record): bool => in_array($record->status, [SocialPost::STATUS_READY, SocialPost::STATUS_SCHEDULED], true)
                    && ! $record->isApproved()
                    && ! $record->isLocked()
                    && static::userCan('social.approve'))
                ->action(fn (SocialPost $record) => static::runAction(fn () => app(SocialPostApprovalService::class)->approve($record, static::actor()), __('Approved.'))),

            Action::make('revokeApproval')
                ->label(__('Take approval back'))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (SocialPost $record): bool => $record->isApproved()
                    && $record->publish_state === SocialPost::PUBLISH_IDLE
                    && static::userCan('social.approve'))
                ->action(fn (SocialPost $record) => static::runAction(fn () => app(SocialPostApprovalService::class)->revoke($record, static::actor()), __('Approval taken back.'))),

            Action::make('schedule')
                ->label(__('Schedule'))
                ->icon(Heroicon::OutlinedClock)
                ->visible(fn (SocialPost $record): bool => $record->isApproved()
                    && $record->status === SocialPost::STATUS_READY
                    && $record->publish_state === SocialPost::PUBLISH_IDLE
                    && static::userCan('social.publish'))
                ->schema(fn (SocialPost $record): array => [
                    DateTimePicker::make('scheduled_at')
                        ->label(__('Publish at (:timezone)', ['timezone' => $record->timezone()]))
                        ->native(false)
                        ->seconds(false)
                        ->timezone($record->timezone())
                        ->minDate(now())
                        ->required(),
                ])
                // The picker shows the company's time and hands back the application's (UTC) time.
                ->action(fn (SocialPost $record, array $data) => static::runAction(
                    fn () => app(SocialPostPublishingService::class)->schedule($record, Carbon::parse((string) $data['scheduled_at'], config('app.timezone')), static::actor()),
                    __('Scheduled.'),
                )),

            Action::make('unschedule')
                ->label(__('Take off the schedule'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (SocialPost $record): bool => $record->status === SocialPost::STATUS_SCHEDULED
                    && $record->publish_state === SocialPost::PUBLISH_IDLE
                    && static::userCan('social.publish'))
                ->action(fn (SocialPost $record) => static::runAction(fn () => app(SocialPostPublishingService::class)->unschedule($record, static::actor()), __('Taken off the schedule.'))),

            Action::make('publishNow')
                ->label(__('Publish now'))
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('The post is sent to the selected platforms right away.'))
                ->visible(fn (SocialPost $record): bool => $record->isApproved()
                    && in_array($record->status, [SocialPost::STATUS_READY, SocialPost::STATUS_SCHEDULED], true)
                    && $record->publish_state === SocialPost::PUBLISH_IDLE
                    && static::userCan('social.publish'))
                ->action(fn (SocialPost $record) => static::runAction(fn () => app(SocialPostPublishingService::class)->publishNow($record, static::actor()), __('Queued. It will be published in a moment.'))),

            Action::make('retry')
                ->label(__('Retry'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription(__('Only the platforms that did not go out are sent again.'))
                ->visible(fn (SocialPost $record): bool => $record->publish_state === SocialPost::PUBLISH_FAILED
                    && ! $record->hasUncertainPlatform()
                    && static::userCan('social.publish'))
                ->action(fn (SocialPost $record) => static::runAction(fn () => app(SocialPostPublishingService::class)->retry($record, static::actor()), __('Queued again.'))),

            Action::make('reconcile')
                ->label(__('Reconcile'))
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('warning')
                ->modalDescription(__('A platform did not give a clear answer, so nobody knows whether the post went out. Open the account, check, and tell the system what you found. Nothing is sent again until you do.'))
                ->visible(fn (SocialPost $record): bool => $record->hasUncertainPlatform() && static::userCan('social.publish'))
                ->schema(fn (SocialPost $record): array => [
                    Select::make('platform')
                        ->label(__('Platform'))
                        ->options(fn (): array => collect((array) $record->publish_progress)
                            ->filter(fn (array $state): bool => ($state['state'] ?? null) === 'uncertain')
                            ->mapWithKeys(fn (array $state, string $platform): array => [$platform => __(SocialPost::PLATFORMS[$platform] ?? ucfirst($platform))])
                            ->all())
                        ->required()
                        ->native(false),
                    Radio::make('outcome')
                        ->label(__('What did you find?'))
                        ->options([
                            'found' => __('The post is there'),
                            'absent' => __('The post is not there'),
                        ])
                        ->required()
                        ->live(),
                    TextInput::make('platform_post_id')
                        ->label(__('Post id on the platform'))
                        ->helperText(__('Digits only (Facebook shows them with an underscore, like 123_456).'))
                        ->visible(fn (Get $get): bool => $get('outcome') === 'found')
                        ->required(fn (Get $get): bool => $get('outcome') === 'found')
                        ->maxLength(64),
                ])
                ->action(fn (SocialPost $record, array $data) => static::runAction(
                    fn () => ($data['outcome'] ?? null) === 'found'
                        ? app(SocialPostPublishingService::class)->reconcileWithId($record, (string) $data['platform'], (string) ($data['platform_post_id'] ?? ''), static::actor())
                        : app(SocialPostPublishingService::class)->reconcileAbsent($record, (string) $data['platform'], static::actor()),
                    __('Recorded.'),
                )),

            Action::make('archive')
                ->label(__('Archive'))
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (SocialPost $record): bool => $record->status !== SocialPost::STATUS_ARCHIVED
                    && ! $record->isLocked()
                    && static::userCan('social.delete'))
                ->action(fn (SocialPost $record) => static::runAction(fn () => app(SocialPostPublishingService::class)->archive($record, static::actor()), __('Archived.'))),
        ];
    }

    /** Runs a service call; a refusal becomes a red notification with the reason instead of an error page. */
    protected static function runAction(callable $call, string $success): void
    {
        try {
            $call();
        } catch (SocialPostException $exception) {
            Notification::make()->title(__('Not done'))->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }

    protected static function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected static function userCan(string $permission): bool
    {
        return Auth::user()?->hasPermission($permission) ?? false;
    }

    /** A post that is being published, needs reconciling, or is finished is shown but not edited. */
    public static function isReadOnly(SocialPost $record): bool
    {
        return $record->isLocked()
            || in_array($record->status, [SocialPost::STATUS_POSTED, SocialPost::STATUS_ARCHIVED], true);
    }

    public static function canViewAny(): bool
    {
        return SchemaFacade::hasTable('social_posts') && static::userCan('social.view');
    }

    public static function canCreate(): bool
    {
        return SchemaFacade::hasTable('social_posts')
            && (app(CompanyContext::class)->hasCompany()
                || (app(CompanyContext::class)->isAllCompanies() && (Auth::user()?->isSuperAdmin() ?? false)))
            && static::userCan('social.create');
    }

    /** Opening a post needs only `social.view`; what can be changed is decided by the status and the form. */
    public static function canEdit(Model $record): bool
    {
        if (! SchemaFacade::hasTable('social_posts') || ! static::userCan('social.view')) {
            return false;
        }

        if (static::isReadOnly($record)) {
            return true;
        }

        return in_array($record->status, [SocialPost::STATUS_DRAFT, SocialPost::STATUS_BLOCKED], true)
            ? static::userCan('social.create')
            : static::userCan('social.update');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSocialPosts::route('/'),
            'create' => CreateSocialPost::route('/create'),
            'edit' => EditSocialPost::route('/{record}/edit'),
        ];
    }
}
