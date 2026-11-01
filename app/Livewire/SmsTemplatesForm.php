<?php

namespace App\Livewire;

use App\Filament\Pages\Integrations;
use App\Models\Company;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\SmsTemplateService;
use App\Support\BulkSmsBdPolicy;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Where the owner writes the SMS the system sends (owner request 2026-10-17,
 * moved into the SMS Gateway tab 2026-10-18): order status messages, sign-in /
 * password-reset codes and the investors' project status messages. Embedded on
 * Settings → Integrations → SMS Gateway, same Filament\Schemas\Components\Livewire
 * pattern as WebsiteApiKeysTable there, with its own Save button.
 *
 * The sending is in SmsTemplateService, OrderStatusSmsService and
 * InvestorProjectMessageService. Every message follows the BulkSMSBD rules
 * (BulkSmsBdPolicy): a code message can only change its brand, and a message
 * that breaks a rule is not saved. The templates belong to the company, so
 * every site's gateway uses the same ones.
 */
class SmsTemplatesForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** The form field of a message (a key has dots, which a form path cannot). */
    public static function field(string $key): string
    {
        return 'sms_'.str_replace('.', '_', $key);
    }

    public function company(): ?Company
    {
        $context = app(CompanyContext::class);

        return $context->isAllCompanies() ? null : $context->company();
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
                Section::make(__('BulkSMSBD SMS rules'))
                    ->description(__('Every message follows the BulkSMSBD rules: a code message reads exactly "Your {brand or company name} OTP is :code"; no single quotation mark (\'); at most 3 SMS parts (160 characters a part in English, 70 with Bangla letters). A message that breaks a rule is not saved.'))
                    ->schema([
                        Text::make(fn (): string => __('Your SMS sender ID (:sender) is a masking name. BulkSMSBD sends masking SMS only in Bangla (error 1012), so an English message, including the OTP format, is refused by the gateway. A numeric sender ID sends English messages.', ['sender' => (string) $this->senderId()]))
                            ->visible(fn (): bool => BulkSmsBdPolicy::isMaskingSender($this->senderId())),
                    ])
                    ->compact(),
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
                                Text::make(__('The one-time codes. BulkSMSBD accepts only its OTP format, so you can change only the brand name: Your {brand or company name} OTP is :code. Leave a message empty to send the built-in wording shown in grey.')),
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
            $field = self::field($key);
            $input = Textarea::make($field)
                ->label($definition['label'])
                ->rows(3)
                ->maxLength(SmsTemplateService::MAX_LENGTH)
                ->placeholder($definition['default'])
                ->live(debounce: 500)
                ->hint(fn (Get $get): string => $this->lengthNote((string) ($get($field) ?: $definition['default'])))
                ->helperText($this->placeholderHelp($definition['placeholders'], $definition['default'] !== null))
                ->columnSpanFull();

            if ($definition['suggested'] !== null) {
                $input->hintAction(Action::make('suggest_'.$field)
                    ->label(__('Use suggested wording'))
                    ->action(fn (Set $set) => $set($field, $definition['suggested'])));
            }

            $fields[] = $input;
        }

        return $fields;
    }

    /** The company's SMS sender ID (from the gateway row that sends its SMS), or null. */
    protected function senderId(): ?string
    {
        $company = $this->company();
        $setting = $company ? app(SmsTemplateService::class)->gatewayFor((int) $company->getKey()) : null;

        return $setting ? data_get($setting->notification_credentials, 'sms_sender_id') : null;
    }

    /** "112 characters, 1 SMS (GSM-7)": the placeholders count as written, so the real SMS differs a little. */
    protected function lengthNote(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $length = BulkSmsBdPolicy::length($text);

        return __(':units characters, :parts SMS (:encoding)', ['units' => $length['units'], 'parts' => $length['parts'], 'encoding' => $length['encoding']]);
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

        // The Integrations page's own rule: settings managers of this company (a Super Admin for all companies).
        abort_unless($company !== null && Integrations::canAccess(), 404);

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

    public function render(): View
    {
        return view('livewire.sms-templates-form');
    }
}
