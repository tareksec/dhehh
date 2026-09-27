<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\AiTools;
use App\Services\CompanyContext;
use App\Services\ImageGeneration\ImageGovernanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * 11_AI_TOOL_MENU_IMAGE_GENERATION_PLAN.md §11 Phase 5 — governance & polish.
 *
 * Super-admin screen with two halves:
 *  - Controls: a monthly image cap per built-in role (0 = unlimited) and the
 *    roles whose generations need a reviewer's sign-off before they can be
 *    attached to a product / offer. All default to off — the owner sets real
 *    numbers here, the same way every external credential is plugged in.
 *  - Usage: this calendar month's image count and estimated spend, broken
 *    down by user and by provider (estimate uses each provider profile's
 *    admin-set "approx. cost per image" — nothing is read from a billing API).
 *
 * Super-admin-only, consistent with the other AI Tools configuration pages
 * (Image Providers, Prompt Enhancer). Kept out of the sidebar — the AI Tools
 * menu lists only the tools — and opened from the "Image Governance" header
 * button on the Tool Menu.
 */
class ImageGovernance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $cluster = AiTools::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Image Governance';

    protected static ?string $title = 'Image Governance';

    protected string $view = 'filament.pages.image-governance';

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
            $this->form->fill();

            return;
        }

        $config = app(ImageGovernanceService::class)->all(app(CompanyContext::class)->company());

        $fill = ['review_roles' => $config['review_roles']];

        foreach (array_keys(ImageGovernanceService::governableRoles()) as $role) {
            $fill[$this->capField($role)] = $config['monthly_caps'][$role] ?? 0;
        }

        $this->form->fill($fill);
    }

    public function form(Schema $schema): Schema
    {
        $roles = ImageGovernanceService::governableRoles();

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Monthly image cap per role')
                    ->description('The most images a user in each role may generate per calendar month for this company. 0 means unlimited. Super admins are always unlimited. Failed generations do not count.')
                    ->columns(2)
                    ->schema(collect($roles)->map(fn (string $label, string $role): TextInput => TextInput::make($this->capField($role))
                        ->label($label)
                        ->numeric()
                        ->minValue(0)
                        ->step(1)
                        ->default(0))->values()->all()),
                Section::make('Approval before attaching')
                    ->description('Generations made by these roles land as “pending review”. The image is still generated, but it cannot be set as a product or offer image until someone with the “approve others’ images” permission approves it on the Image Library.')
                    ->schema([
                        CheckboxList::make('review_roles')
                            ->hiddenLabel()
                            ->options($roles)
                            ->columns(2),
                    ]),
            ]);
    }

    protected function capField(string $role): string
    {
        return 'cap_'.$role;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->icon(Heroicon::OutlinedCheck)
                ->visible(fn (): bool => $this->hasSelectedCompany())
                ->action('save'),
        ];
    }

    public function save(): void
    {
        if (! $this->hasSelectedCompany()) {
            Notification::make()->title('Select a company first')->warning()->send();

            return;
        }

        $state = $this->form->getState();

        $caps = [];
        foreach (array_keys(ImageGovernanceService::governableRoles()) as $role) {
            $caps[$role] = (int) ($state[$this->capField($role)] ?? 0);
        }

        app(ImageGovernanceService::class)->save(app(CompanyContext::class)->company(), [
            'monthly_caps' => $caps,
            'review_roles' => is_array($state['review_roles'] ?? null) ? $state['review_roles'] : [],
        ]);

        $this->mount();

        Notification::make()->title('Governance settings saved')->success()->send();
    }

    /**
     * @return array{images: int, cost: float, by_user: array<int, array<string, mixed>>, by_provider: array<int, array<string, mixed>>}
     */
    public function usage(): array
    {
        if (! $this->hasSelectedCompany()) {
            return ['images' => 0, 'cost' => 0.0, 'by_user' => [], 'by_provider' => []];
        }

        return app(ImageGovernanceService::class)->usageSummary(app(CompanyContext::class)->company());
    }
}
