<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Pages\Concerns\OpenedFromIntegrations;
use App\Services\CompanyContext;
use App\Services\ExpenseScan\ExpenseScanConfigService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Super-admin config for Smart Scan (Finance → Smart Scans): the
 * vision model that reads photographed expense notes. Its own
 * provider/model/key, stored encrypted per company via
 * ExpenseScanConfigService at settings->ai_tools->expense_scan — kept apart
 * from the CRM / Prompt Enhancer keys so its cost is tracked on its own.
 *
 * Super-admin-only, consistent with the other AI provider configuration
 * pages. Opened from the "AI tool providers" cards on Settings → Integrations.
 */
class ExpenseScanSettings extends Page
{
    use OpenedFromIntegrations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.expense-scan-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Smart Scan');
    }

    public function getTitle(): string
    {
        return __('Smart Scan');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        if (! $this->hasSelectedCompany()) {
            return;
        }

        $config = app(ExpenseScanConfigService::class)->all(app(CompanyContext::class)->company());

        $this->form->fill([
            'enabled' => (bool) $config['enabled'],
            'api_format' => $config['api_format'],
            'provider' => $config['provider'],
            'base_url' => $config['base_url'],
            'model' => $config['model'],
            'api_key' => '',
            'has_api_key' => filled($config['api_key']),
        ]);
    }

    public function hasSelectedCompany(): bool
    {
        $context = app(CompanyContext::class);

        return $context->hasCompany() && ! $context->isAllCompanies();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Vision model')
                    ->description('Reads photos of handwritten or printed expense notes (Bangla and English) into draft expense lines. The model must support image input. Drafts are never published until someone reviews them.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('Enable Smart Scan for this company'))
                            ->columnSpanFull(),
                        Select::make('api_format')
                            ->label('API format')
                            ->options([
                                'anthropic' => 'Anthropic (Claude Messages API)',
                                'openai' => 'OpenAI-compatible (Chat Completions)',
                            ])
                            ->required()
                            ->native(false),
                        TextInput::make('provider')
                            ->label('Provider name (label only)')
                            ->maxLength(100)
                            ->placeholder('e.g. Anthropic, OpenAI'),
                        TextInput::make('base_url')
                            ->label('Base URL (optional)')
                            ->url()
                            ->maxLength(500)
                            ->placeholder("Leave blank for the API format's default endpoint")
                            ->columnSpanFull(),
                        TextInput::make('model')
                            ->label('Model')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('claude-opus-5'),
                        TextInput::make('api_key')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->maxLength(500)
                            ->placeholder(fn (Get $get): string => $get('has_api_key') ? '••••••••' : 'sk-...')
                            ->helperText(fn (Get $get): string => $get('has_api_key')
                                ? 'Saved and encrypted — leave blank to keep it.'
                                : 'Stored encrypted per company.'),
                    ]),
            ]);
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

        app(ExpenseScanConfigService::class)->save(app(CompanyContext::class)->company(), [
            'enabled' => (bool) ($state['enabled'] ?? false),
            'api_format' => $state['api_format'] ?? 'anthropic',
            'provider' => $state['provider'] ?? null,
            'base_url' => $state['base_url'] ?? null,
            'model' => $state['model'] ?? null,
            'api_key' => $state['api_key'] ?? null,
        ]);

        $this->mount();

        Notification::make()->title(__('Smart Scan settings saved'))->success()->send();
    }
}
