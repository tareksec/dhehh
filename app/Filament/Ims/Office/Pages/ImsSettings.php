<?php

namespace App\Filament\Ims\Office\Pages;

use App\Ims\Settings\ImsSettings as Settings;
use App\Models\Company;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * IMS settings (IMS-V2/ROLES.md `ims.settings.manage`): which news goes by
 * SMS and e-mail (always in the portal), joining by invite on/off, the
 * maturity reminder, and the daily jobs. The IMS domain itself is set by a
 * Super Admin in the ERP (Investments → Investor Portal Settings).
 */
class ImsSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.ims.office.pages.ims-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('ims.settings.manage') ?? false;
    }

    public function getTitle(): string
    {
        return __('IMS settings');
    }

    protected function company(): Company
    {
        return app(CompanyContext::class)->company();
    }

    public function mount(): void
    {
        $this->form->fill(Settings::all($this->company()));
    }

    public function form(Schema $schema): Schema
    {
        $channelRows = [];

        foreach (Settings::EVENTS as $event => $label) {
            $channelRows[] = Grid::make(['default' => 1, 'sm' => 3])->schema([
                Text::make(__($label))->columnSpan(['default' => 1, 'sm' => 1]),
                Toggle::make("channels.{$event}.sms")->label(__('SMS')),
                Toggle::make("channels.{$event}.email")->label(__('E-mail')),
            ]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('IMS domain'))
                    ->schema([
                        Text::make(fn (): string => __('Investor portal: :portal · Office: :office', [
                            'portal' => (string) $this->company()->imsUrl(),
                            'office' => (string) $this->company()->imsUrl('office'),
                        ])),
                        Text::make(__('A Super Admin changes the domain in the ERP: Investments → Investor Portal Settings.')),
                    ]),
                Section::make(__('Which news goes how'))
                    ->description(__('Every piece of news is always shown in the investor portal. Choose which also go by SMS and by e-mail. E-mail reaches only investors who have an e-mail address.'))
                    ->schema($channelRows),
                Section::make(__('Joining'))
                    ->schema([
                        Toggle::make('invite_registration_enabled')
                            ->label(__('Investors may join with an office invite and a one-time code'))
                            ->helperText(__('Off: invites cannot be used. Investors added by the office are not affected.')),
                    ]),
                Section::make(__('Reminders and daily work'))
                    ->schema([
                        TextInput::make('maturity_reminder_days')
                            ->label(__('Remind before maturity (days)'))
                            ->numeric()->minValue(1)->maxValue(60)->required(),
                        Toggle::make('jobs.profit_proposals')->label(__('Make a profit proposal when a cycle ends')),
                        Toggle::make('jobs.maturity_reminders')->label(__('Send maturity reminders')),
                        Toggle::make('jobs.owner_digest')->label(__('Send the office its to-do list every morning')),
                        Toggle::make('jobs.monthly_statements')->label(__('Send monthly statements on the 1st')),
                        Toggle::make('jobs.shipment_delays')->label(__('Warn when a shipment is late')),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $company = $this->company();
        $before = Settings::all($company);

        Settings::save($company, [
            'channels' => $data['channels'] ?? [],
            'invite_registration_enabled' => (bool) ($data['invite_registration_enabled'] ?? false),
            'maturity_reminder_days' => (int) ($data['maturity_reminder_days'] ?? 7),
            'jobs' => $data['jobs'] ?? [],
        ]);

        app(AuditLogService::class)->record('ims.settings_saved', $company, $before, Settings::all($company->refresh()));

        Notification::make()->title(__('IMS settings saved'))->success()->send();
    }
}
