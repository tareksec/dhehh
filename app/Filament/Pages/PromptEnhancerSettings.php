<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Pages\Concerns\OpenedFromIntegrations;
use App\Models\Company;
use App\Models\GeneratedImage;
use App\Services\CompanyContext;
use App\Services\PromptEnhancement\PromptEnhancerConfigService;
use App\Services\PromptEnhancement\PromptGuideRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Super-admin config for the shared Prompt Enhancer (the ✨ Enhance button on
 * the Image Generation tool, and future tools):
 *
 * - the provider/model/API key the enhancement runs on (its own, so it can
 *   be a small cheap model), stored encrypted per company via
 *   PromptEnhancerConfigService at settings->ai_tools->prompt_enhancer.
 * - the company's visual house style note, folded into every enhancement.
 * - per-context guide overrides — blank means "use the built-in expert
 *   default" from config/prompt_guides.php.
 *
 * Kept super-admin-only, consistent with the other AI provider
 * configuration pages (Image Providers, Image Governance). Opened from the
 * "AI tool providers" cards on Settings → Integrations.
 */
class PromptEnhancerSettings extends Page
{
    use OpenedFromIntegrations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Prompt Enhancer';

    protected static ?string $title = 'Prompt Enhancer';

    protected string $view = 'filament.pages.prompt-enhancer-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        if (! $this->hasSelectedCompany()) {
            return;
        }

        $company = app(CompanyContext::class)->company();
        $config = app(PromptEnhancerConfigService::class)->all($company);
        $repo = app(PromptGuideRepository::class);

        $fill = [
            'enabled' => (bool) $config['enabled'],
            'api_format' => in_array($config['api_format'], ['anthropic', 'openai'], true) ? $config['api_format'] : 'anthropic',
            'provider' => $config['provider'],
            'base_url' => $config['base_url'],
            'model' => $config['model'],
            'api_key' => '',
            'has_api_key' => filled($config['api_key']),
            'brand_style' => $repo->brandStyle($company) ?? '',
        ];

        $overrides = $repo->overrides($company);

        foreach ($this->guideFields() as $field => $contextKey) {
            $fill[$field] = $overrides[$contextKey] ?? '';
        }

        $this->form->fill($fill);
    }

    public function hasSelectedCompany(): bool
    {
        $context = app(CompanyContext::class);

        return $context->hasCompany() && ! $context->isAllCompanies();
    }

    /**
     * Flat, dot-free form field names mapped to their real "{tool}.{context}"
     * guide keys (the keys themselves contain a dot, which Filament would
     * read as nested state).
     *
     * @return array<string, string>
     */
    public function guideFields(): array
    {
        return collect(app(PromptGuideRepository::class)->contextKeys())
            ->mapWithKeys(fn (string $key): array => ['guide__'.str_replace('.', '__', $key) => $key])
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Enhancer model')
                    ->description('The ✨ Enhance button rewrites a rough prompt before generation. This is its own provider/key so you can point it at a small, cheap model.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Enable the ✨ Enhance button for this company')
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
                            ->placeholder('e.g. Anthropic, OpenAI, Groq'),
                        TextInput::make('base_url')
                            ->label('Base URL (optional)')
                            ->url()
                            ->maxLength(500)
                            ->placeholder("Leave blank for the API format's default endpoint")
                            ->columnSpanFull(),
                        TextInput::make('model')
                            ->label('Model')
                            ->maxLength(120)
                            ->placeholder('claude-haiku-4-5-20251001'),
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
                Section::make('Brand visual style')
                    ->description('Folded into every enhancement so images stay on-brand without restating it each time. Optional.')
                    ->schema([
                        Textarea::make('brand_style')
                            ->hiddenLabel()
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('e.g. Warm, premium, natural daylight. Muted earthy palette. Avoid harsh studio flash and neon colours.'),
                    ]),
                Section::make('Context guides')
                    ->description('Expert defaults ship in the app. Override any of them here for this company — leave blank to keep the default.')
                    ->collapsible()
                    ->collapsed()
                    ->schema($this->guideOverrideFields()),
            ]);
    }

    /** @return array<int, Textarea> */
    protected function guideOverrideFields(): array
    {
        $repo = app(PromptGuideRepository::class);

        return collect($this->guideFields())
            ->map(fn (string $contextKey, string $field): Textarea => Textarea::make($field)
                ->label($this->guideLabel($contextKey))
                ->rows(4)
                ->maxLength(4000)
                ->placeholder(Str::limit($repo->configDefault($contextKey), 240)))
            ->values()
            ->all();
    }

    protected function guideLabel(string $contextKey): string
    {
        $context = Str::afterLast($contextKey, '.');

        return (GeneratedImage::CONTEXTS[$context] ?? Str::headline($context)).' guide';
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

        $company = app(CompanyContext::class)->company();
        $state = $this->form->getState();

        app(PromptEnhancerConfigService::class)->save($company, [
            'enabled' => (bool) ($state['enabled'] ?? false),
            'api_format' => $state['api_format'] ?? 'anthropic',
            'provider' => $state['provider'] ?? null,
            'base_url' => $state['base_url'] ?? null,
            'model' => $state['model'] ?? null,
            'api_key' => $state['api_key'] ?? null,
        ]);

        $guides = [];

        foreach ($this->guideFields() as $field => $contextKey) {
            $guides[$contextKey] = $state[$field] ?? '';
        }

        app(PromptGuideRepository::class)->save(
            $company,
            $guides,
            $state['brand_style'] ?? null,
            Auth::id(),
        );

        $this->mount();

        Notification::make()->title('Prompt Enhancer settings saved')->success()->send();
    }

    protected function company(): ?Company
    {
        return app(CompanyContext::class)->company();
    }
}
