<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Company;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\SmsTemplateService;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Where the owner writes the SMS the system sends (owner request 2026-10-17):
 * order status messages, sign-in / password-reset codes and the investors'
 * project status messages. The sending itself is in SmsTemplateService,
 * OrderStatusSmsService and InvestorProjectMessageService; the SMS gateway is
 * set on Integrations → SMS Gateway.
 */
class SmsTemplates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.sms-templates';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('SMS Templates');
    }

    public function getTitle(): string
    {
        return __('SMS Templates');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        $context = app(CompanyContext::class);

        if (! $user?->canManageSettings()) {
            return false;
        }

        if ($context->isAllCompanies()) {
            return $user->isSuperAdmin();
        }

        $company = $context->company();

        return (bool) ($company && $user->canAccessCompany((int) $company->getKey()));
    }

    public function company(): ?Company
    {
        $context = app(CompanyContext::class);

        return $context->isAllCompanies() ? null : $context->company();
    }

    /** The form field of a message (a key has dots, which a form path cannot). */
    public static function field(string $key): string
    {
        return 'sms_'.str_replace('.', '_', $key);
    }

    public function mount(): void
    {
        $company = $this->company();

        if (! $company) {
            return;
        }

        $templates = app(SmsTemplateService::class);
        $bodies = $templates->bodies((int) $company->getKey());
        $state = [];

        foreach (array_keys($templates->definitions()) as $key) {
            $state[self::field($key)] = $bodies[$key] ?? null;
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $templates = app(SmsTemplateService::class);

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('sms_templates')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('Order status'))
                            ->icon(Heroicon::OutlinedTruck)
                            ->schema([
                                Text::make(__('Sent to the customer by SMS when their order reaches that stage. A stage you leave empty sends nothing, so write only the ones you want. Not sent for imported past orders or reseller store orders.')),
                                ...$this->fieldsFor($templates, SmsTemplateService::GROUP_ORDER),
                            ]),
                        Tab::make(__('OTP & password reset'))
                            ->icon(Heroicon::OutlinedKey)
                            ->schema([
                                Text::make(__('The one-time codes. Leave a message empty to send the built-in wording shown in grey. A message must contain :code, or the person receives no code.')),
                                ...$this->fieldsFor($templates, SmsTemplateService::GROUP_CODE),
                            ]),
                        Tab::make(__('Investor projects'))
                            ->icon(Heroicon::OutlinedBriefcase)
                            ->schema([
                                Text::make(__('Sent by SMS to every investor of a project when it changes status. A status you leave empty sends nothing. A group\'s next project is announced when the previous one is completed.')),
                                ...$this->fieldsFor($templates, SmsTemplateService::GROUP_PROJECT),
                            ]),
                    ]),
            ]);
    }

    /** @return list<Textarea> */
    protected function fieldsFor(SmsTemplateService $templates, string $group): array
    {
        $fields = [];

        foreach ($templates->definitionsInGroup($group) as $key => $definition) {
            $fields[] = Textarea::make(self::field($key))
                ->label($definition['label'])
                ->rows(3)
                ->maxLength(SmsTemplateService::MAX_LENGTH)
                ->placeholder($definition['default'])
                ->helperText($this->placeholderHelp($definition['placeholders'], $definition['default'] !== null))
                ->columnSpanFull();
        }

        return $fields;
    }

    /** @param array<string, string> $placeholders */
    protected function placeholderHelp(array $placeholders, bool $hasBuiltIn): string
    {
        $list = collect($placeholders)
            ->map(fn (string $label, string $name): string => ":{$name} = {$label}")
            ->implode('; ');

        return __('You can use: :placeholders.', ['placeholders' => $list])
            .' '.($hasBuiltIn ? __('Leave empty to send the built-in wording.') : __('Leave empty to send nothing.'));
    }

    public function save(): void
    {
        $company = $this->company();

        abort_unless($company !== null && static::canAccess(), 404);

        $templates = app(SmsTemplateService::class);
        $data = $this->form->getState();
        $bodies = [];
        $problems = [];

        foreach (array_keys($templates->definitions()) as $key) {
            $bodies[$key] = $data[self::field($key)] ?? null;

            if ($problem = $templates->problem($key, $bodies[$key])) {
                $problems['data.'.self::field($key)] = $problem;
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $before = $templates->bodies((int) $company->getKey());
        $templates->save((int) $company->getKey(), $bodies);

        app(AuditLogService::class)->record('sms_templates.saved', $company, $before, $templates->bodies((int) $company->getKey()));

        Notification::make()->title(__('SMS templates saved'))->success()->send();
    }
}
