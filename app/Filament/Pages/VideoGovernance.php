<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\AiTools;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoGovernanceService;
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
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md Phase 5 — governance & polish,
 * the Video Generation twin of ImageGovernance.
 *
 *  - Controls: a monthly video cap per built-in role (0 = unlimited) and the
 *    roles whose videos need a reviewer's sign-off before they can be
 *    attached to a product / offer. All default to off.
 *  - Usage: this calendar month's video count and estimated spend, by user
 *    and by provider (from each provider profile's admin-set "approx. cost
 *    per video" — nothing is read from a billing API).
 *
 * Super-admin-only; kept out of the sidebar and opened from the "Video
 * Governance" header button on the Tool Menu, like Image Governance.
 */
class VideoGovernance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $cluster = AiTools::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.video-governance';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Video Governance');
    }

    public function getTitle(): string
    {
        return __('Video Governance');
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

        $config = app(VideoGovernanceService::class)->all(app(CompanyContext::class)->company());

        $fill = ['review_roles' => $config['review_roles']];

        foreach (array_keys(VideoGovernanceService::governableRoles()) as $role) {
            $fill[$this->capField($role)] = $config['monthly_caps'][$role] ?? 0;
        }

        $this->form->fill($fill);
    }

    public function form(Schema $schema): Schema
    {
        $roles = VideoGovernanceService::governableRoles();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Monthly video cap per role'))
                    ->description(__('The most videos a user in each role may generate per calendar month for this company. 0 means unlimited. Super admins are always unlimited. Failed generations do not count.'))
                    ->columns(2)
                    ->schema(collect($roles)->map(fn (string $label, string $role): TextInput => TextInput::make($this->capField($role))
                        ->label($label)
                        ->numeric()
                        ->minValue(0)
                        ->step(1)
                        ->default(0))->values()->all()),
                Section::make(__('Approval before attaching'))
                    ->description(__('Videos made by these roles land as “pending review”. The video is still generated, but it cannot be put on a product or offer until someone with the “approve others’ videos” permission approves it in the Video Library.'))
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
                ->label(__('Save'))
                ->icon(Heroicon::OutlinedCheck)
                ->visible(fn (): bool => $this->hasSelectedCompany())
                ->action('save'),
        ];
    }

    public function save(): void
    {
        if (! $this->hasSelectedCompany()) {
            Notification::make()->title(__('Select a company first'))->warning()->send();

            return;
        }

        $state = $this->form->getState();

        $caps = [];
        foreach (array_keys(VideoGovernanceService::governableRoles()) as $role) {
            $caps[$role] = (int) ($state[$this->capField($role)] ?? 0);
        }

        app(VideoGovernanceService::class)->save(app(CompanyContext::class)->company(), [
            'monthly_caps' => $caps,
            'review_roles' => is_array($state['review_roles'] ?? null) ? $state['review_roles'] : [],
        ]);

        $this->mount();

        Notification::make()->title(__('Governance settings saved'))->success()->send();
    }

    /**
     * @return array{videos: int, cost: float, by_user: array<int, array<string, mixed>>, by_provider: array<int, array<string, mixed>>}
     */
    public function usage(): array
    {
        if (! $this->hasSelectedCompany()) {
            return ['videos' => 0, 'cost' => 0.0, 'by_user' => [], 'by_provider' => []];
        }

        return app(VideoGovernanceService::class)->usageSummary(app(CompanyContext::class)->company());
    }
}
