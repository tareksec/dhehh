<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Pages\Concerns\OpenedFromIntegrations;
use App\Services\CompanyContext;
use App\Services\ImageGeneration\ImageProviderSettingsService;
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
 * 11_AI_TOOL_MENU_IMAGE_GENERATION_PLAN.md §6.1 / §7 — where a super admin
 * registers the image providers the Image Generation tool can use. Each
 * profile has its own model and **encrypted-per-company** API key; staff pick
 * one at generation time.
 *
 * Its own page (not an inline Integrations form tab), hidden from the
 * sidebar and opened from the "AI tool providers" cards on Settings →
 * Integrations, alongside Video Providers, Prompt Enhancer and Smart Scan —
 * the AI Tools menu itself lists only the tools.
 * ImageProviderSettingsService is the only thing that touches the underlying
 * `companies.settings->ai_tools->image_generation` list.
 */
class ImageProviderSettings extends Page
{
    use OpenedFromIntegrations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Image Providers';

    protected static ?string $title = 'Image Providers';

    protected string $view = 'filament.pages.image-provider-settings';

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
            $this->form->fill(['image_provider_profiles' => []]);

            return;
        }

        $this->form->fill([
            'image_provider_profiles' => app(ImageProviderSettingsService::class)
                ->list(app(CompanyContext::class)->company()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Repeater::make('image_provider_profiles')
                    ->label('Image providers')
                    ->addActionLabel('Add image provider')
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
                ->placeholder('e.g. OpenAI — high quality'),
            Select::make('api_format')
                ->label('Provider type')
                ->options(ImageProviderSettingsService::API_FORMATS)
                ->default('openai')
                ->required()
                ->native(false)
                ->live(),
            TextInput::make('model')
                ->label('Model')
                ->required()
                ->maxLength(120)
                ->placeholder(fn (Get $get): string => match ($get('api_format')) {
                    'google' => 'imagen-4.0-generate-001',
                    'stability' => 'core',
                    'openrouter' => 'google/gemini-2.5-flash-image',
                    default => 'gpt-image-1',
                }),
            TextInput::make('default_size')
                ->label('Default size')
                ->maxLength(20)
                ->placeholder(ImageProviderSettingsService::DEFAULT_SIZE)
                ->helperText('Used by providers that take a pixel size (OpenAI). Ratio-based providers use the aspect ratio picked at generation time.'),
            TextInput::make('cost_per_image')
                ->label('Approx. cost per image')
                ->numeric()
                ->minValue(0)
                ->step('0.0001')
                ->default(0)
                ->helperText('Optional. Used only to estimate spend on the AI Tools → Image Governance usage dashboard — not billed.'),
            TextInput::make('base_url')
                ->label(fn (Get $get): string => $get('api_format') === 'custom' ? 'Base URL (required)' : 'Base URL (optional)')
                ->url()
                ->maxLength(500)
                ->required(fn (Get $get): bool => $get('api_format') === 'custom')
                ->placeholder("Leave blank for the provider's default endpoint")
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

        app(ImageProviderSettingsService::class)->save(
            app(CompanyContext::class)->company(),
            is_array($state['image_provider_profiles'] ?? null) ? $state['image_provider_profiles'] : [],
        );

        $this->mount();

        Notification::make()->title('Image providers saved')->success()->send();
    }
}
