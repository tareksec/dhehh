<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Pages\Concerns\OpenedFromIntegrations;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §6.1 / §7.3 — where a super admin
 * registers the video providers the Video Generation tool can use. Each
 * profile has its own **encrypted-per-company** API key; staff pick one at
 * generation time. Line-for-line mirror of ImageProviderSettings — its own
 * page, opened from the "AI tool providers" cards on Settings → Integrations.
 * VideoProviderSettingsService is the only thing that touches the underlying
 * `companies.settings->ai_tools->video_generation` list.
 */
class VideoProviderSettings extends Page
{
    use OpenedFromIntegrations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Video Providers';

    protected static ?string $title = 'Video Providers';

    protected string $view = 'filament.pages.video-provider-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function hasSelectedCompany(): bool
    {
        $context = app(CompanyContext::class);

        return $context->hasCompany() && ! $context->isAllCompanies();
    }

    public function mount(): void
    {
        if (! $this->hasSelectedCompany()) {
            $this->form->fill(['video_provider_profiles' => []]);

            return;
        }

        $this->form->fill([
            'video_provider_profiles' => app(VideoProviderSettingsService::class)
                ->list(app(CompanyContext::class)->company()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Repeater::make('video_provider_profiles')
                    ->label('Video providers')
                    ->addActionLabel('Add video provider')
                    ->reorderable(false)
                    ->collapsible()
                    ->collapsed()
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->schema($this->providerFields())
                    ->columnSpanFull(),
            ]);
    }

    /**
     * One row of the provider list. `id` and `has_api_key` are Hidden so they
     * survive the repeater's dehydrate — the service matches an existing
     * profile by `id` to keep its stored (encrypted) key when the key field
     * is left blank.
     *
     * @return array<int, Field>
     */
    protected function providerFields(): array
    {
        return [
            Hidden::make('id'),
            Hidden::make('has_api_key'),
            TextInput::make('label')
                ->label('Display name')
                ->required()
                ->maxLength(100)
                ->placeholder('e.g. Stability — image to video'),
            Select::make('api_format')
                ->label('Provider type')
                ->options(VideoProviderSettingsService::API_FORMATS)
                ->default('stability')
                ->required()
                ->native(false)
                ->live(),
            TextInput::make('model')
                ->label('Model')
                ->maxLength(120)
                ->helperText(fn (Get $get): string => $get('api_format') === 'stability'
                    ? "Not used — Stability's image-to-video endpoint has no model choice."
                    : 'Optional. Forwarded to the endpoint if it accepts one.')
                ->visible(fn (Get $get): bool => $get('api_format') !== 'stability'),
            TextInput::make('cost_per_video')
                ->label('Approx. cost per video')
                ->numeric()
                ->minValue(0)
                ->step('0.0001')
                ->default(0)
                ->helperText('Optional. Used only to estimate spend on the future AI Tools → Video Governance usage dashboard — not billed.'),
            TextInput::make('base_url')
                ->label(fn (Get $get): string => $get('api_format') === 'custom' ? 'Submit URL (required)' : 'Base URL (optional)')
                ->url()
                ->maxLength(500)
                ->required(fn (Get $get): bool => $get('api_format') === 'custom')
                ->placeholder("Leave blank for the provider's default endpoint")
                ->columnSpanFull(),
            TextInput::make('poll_url_template')
                ->label('Poll URL template')
                ->maxLength(500)
                ->required(fn (Get $get): bool => $get('api_format') === 'custom')
                ->visible(fn (Get $get): bool => $get('api_format') === 'custom')
                ->placeholder('https://your-host/jobs/{job_id}')
                ->helperText('The status-check URL for a submitted job. {job_id} is replaced with the provider\'s own job id.')
                ->columnSpanFull(),
            TextInput::make('api_key')
                ->label('API key')
                ->password()
                ->revealable()
                ->maxLength(500)
                ->placeholder(fn (Get $get): string => $get('has_api_key') ? '••••••••' : 'sk-...')
                ->helperText(fn (Get $get): string => $get('has_api_key')
                    ? 'Saved and encrypted — leave blank to keep it.'
                    : 'Stored encrypted per company.'),
            Toggle::make('is_default')
                ->label("Use as this company's default provider")
                ->columnSpanFull(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon(Heroicon::OutlinedCheck)
                ->keyBindings(['mod+s'])
                ->visible(fn (): bool => $this->hasSelectedCompany())
                ->action('save'),
        ];
    }

    public function save(): void
    {
        if (! $this->hasSelectedCompany()) {
            Notification::make()->title('Select a single company first')->warning()->send();

            return;
        }

        $state = $this->form->getState();

        app(VideoProviderSettingsService::class)->save(
            app(CompanyContext::class)->company(),
            is_array($state['video_provider_profiles'] ?? null) ? $state['video_provider_profiles'] : [],
        );

        $this->mount();

        Notification::make()->title('Video providers saved')->success()->send();
    }
}
