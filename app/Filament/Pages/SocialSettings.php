<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Social;
use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaGraphService;
use App\Services\Social\SocialSettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Per-company Social connection (19_SOCIAL_CONTENT_PLANNER_PLAN.md §5): the Facebook Page id, the Instagram account id
 * and a Page access token made for publishing. The token is stored encrypted and never shown again; a blank token field
 * keeps the one already saved. A separate token from the Inbox's Messenger token and the Ads token, on purpose.
 */
class SocialSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $cluster = Social::class;

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.social-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Social Settings');
    }

    public function getTitle(): string
    {
        return __('Social Settings');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('social.settings') ?? false;
    }

    public function hasSelectedCompany(): bool
    {
        $context = app(CompanyContext::class);

        return $context->hasCompany() && ! $context->isAllCompanies();
    }

    public function mount(): void
    {
        if (! $this->hasSelectedCompany()) {
            $this->form->fill([]);

            return;
        }

        $settings = app(SocialSettingsService::class)->forDisplay($this->company());

        $this->form->fill([
            'enabled' => $settings['enabled'],
            'page_id' => $settings['page_id'],
            'instagram_account_id' => $settings['instagram_account_id'],
            'page_access_token' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Meta connection'))
                    ->description(__('Posts go to the Facebook Page and Instagram account below with their own access token. Use a Page access token made for publishing (permissions to try: pages_manage_posts, pages_read_engagement, instagram_basic and instagram_content_publish), not the Messenger or Ads token.'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('page_id')
                            ->label(__('Facebook Page id'))
                            ->maxLength(64),
                        TextInput::make('instagram_account_id')
                            ->label(__('Instagram business account id'))
                            ->helperText(__('A professional (business or creator) Instagram account linked to the Page.'))
                            ->maxLength(64),
                        TextInput::make('page_access_token')
                            ->label(__('Page access token'))
                            ->password()
                            ->revealable()
                            ->maxLength(2000)
                            ->helperText(fn (): string => $this->hasSavedToken()
                                ? __('A token is saved. It is never shown again; leave this blank to keep it.')
                                : __('Not saved yet. It is stored encrypted and never shown again.')),
                        Toggle::make('enabled')
                            ->label(__('Allow publishing for this company'))
                            ->helperText(__('Needs the second queue worker (the `social` worker) to be running. It can only be turned on after that worker has been seen.')),
                    ])
                    ->columns(2),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess() && $this->hasSelectedCompany(), 403);

        $data = $this->form->getState();
        $service = app(SocialSettingsService::class);

        if (($data['enabled'] ?? false) && ! $service->workerSeenRecently()) {
            $data['enabled'] = false;

            Notification::make()
                ->title(__('Publishing was not switched on'))
                ->body(__('The social queue worker has not been seen yet. Start it (see the note below), wait a few minutes, then switch publishing on.'))
                ->warning()
                ->send();
        }

        $service->save($this->company(), $data);

        $this->data['page_access_token'] = null;
        $this->data['enabled'] = (bool) ($data['enabled'] ?? false);

        Notification::make()->title(__('Saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label(__('Test connection'))
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->visible(fn (): bool => $this->hasSelectedCompany())
                ->action(fn () => $this->testConnection()),
        ];
    }

    /** Reads the Page (and the Instagram account) with the SAVED token: nothing is posted. */
    public function testConnection(): void
    {
        abort_unless(static::canAccess() && $this->hasSelectedCompany(), 403);

        $company = $this->company();
        $settings = app(SocialSettingsService::class)->all($company);
        $graph = app(MetaGraphService::class);

        if (blank($settings['page_access_token']) || blank($settings['page_id'])) {
            Notification::make()
                ->title(__('Save the Page id and the access token first.'))
                ->warning()
                ->send();

            return;
        }

        try {
            $page = $graph->pageProfile($settings['page_id'], (string) $settings['page_access_token']);
            $lines = [__('Facebook Page: :name', ['name' => $page['name'] ?: $page['id']])];

            if (filled($settings['instagram_account_id'])) {
                $instagram = $graph->instagramProfile($settings['instagram_account_id'], (string) $settings['page_access_token']);
                $lines[] = __('Instagram: :name', ['name' => $instagram['username'] ?: $instagram['id']]);
            }
        } catch (MetaGraphException $exception) {
            Notification::make()
                ->title(__('Connection failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        app(SocialSettingsService::class)->markSuccess($company);

        Notification::make()
            ->title(__('Connection works'))
            ->body(implode("\n", $lines))
            ->success()
            ->send();
    }

    public function hasSavedToken(): bool
    {
        return $this->hasSelectedCompany() && app(SocialSettingsService::class)->hasToken($this->company());
    }

    public function workerSeen(): bool
    {
        return app(SocialSettingsService::class)->workerSeenRecently();
    }

    public function lastSuccessAt(): ?string
    {
        if (! $this->hasSelectedCompany()) {
            return null;
        }

        $company = $this->company();

        return app(SocialSettingsService::class)->lastSuccessAt($company)
            ?->setTimezone((string) ($company->timezone ?: config('app.timezone')))
            ->format('d M Y, h:i A');
    }

    protected function company(): Company
    {
        $company = app(CompanyContext::class)->company();

        abort_unless($company !== null, 404);

        return $company;
    }
}
