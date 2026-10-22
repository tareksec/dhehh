<?php

namespace App\Filament\Ims\Portal\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Filament\Resources\Investors\RelationManagers\NomineesRelationManager;
use App\Ims\Onboarding\NomineeService;
use App\Models\InvestorNominee;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** The investor's own nominees (IMS-V2/PRD.md FR-06): one to five, shares adding up to 100%. */
class Nominees extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.ims.portal.nominees';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('Account');
    }

    public static function getNavigationLabel(): string
    {
        return __('Nominees');
    }

    public function getTitle(): string
    {
        return __('Nominees');
    }

    public function mount(): void
    {
        $nominees = $this->account()->investor->nominees()->get();

        $this->form->fill(['nominees' => $nominees->isEmpty()
            ? [['name' => null, 'share_percent' => 100]]
            : $nominees->map(fn (InvestorNominee $nominee): array => [
                'name' => $nominee->name,
                'relation' => $nominee->relation,
                'phone' => $nominee->phone,
                'nid' => $nominee->nid,
                'address' => $nominee->address,
                'share_percent' => (float) $nominee->share_percent,
            ])->all()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([NomineesRelationManager::nomineesRepeater()->disabled(fn (): bool => $this->isStaffPreview())]);
    }

    public function save(): void
    {
        $this->refuseInStaffPreview();

        $data = $this->form->getState();
        app(NomineeService::class)->replace($this->account()->investor, $data['nominees'] ?? [], 'investor');

        Notification::make()->title(__('Nominees saved'))->success()->send();
    }
}
