<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\StorefrontSetting;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\EmailTemplateService;
use App\Services\StorefrontNotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

/**
 * Where the owner writes the emails the system sends (owner request
 * 2026-10-24): order emails to customers, login and password-reset mails,
 * the investors' IMS emails, and templates of her own that staff send by hand
 * from an order. The wording, the placeholders and the branded layout are in
 * EmailTemplateService; the order emails go out through OrderStatusEmailService,
 * through the email account on Integrations → Email Setup. A message you leave
 * empty sends nothing (order emails) or the built-in mail (the rest).
 */
class EmailTemplates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.email-templates';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Email Templates');
    }

    public function getTitle(): string
    {
        return __('Email Templates');
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

    /** The form field of a message part (a key has dots, which a form path cannot). */
    public static function field(string $key, string $part): string
    {
        return $part.'_'.str_replace('.', '_', $key);
    }

    /** Whether the company can send email at all (an email account on a site, or a real server mailer). */
    public function emailReady(): bool
    {
        $company = $this->company();
        $setting = $company ? StorefrontSetting::primaryFor((int) $company->getKey()) : null;

        return $setting !== null && app(StorefrontNotificationService::class)->emailDeliveryAvailable($setting);
    }

    public function mount(): void
    {
        $company = $this->company();

        if (! $company) {
            return;
        }

        $templates = app(EmailTemplateService::class);
        $rows = $templates->rows((int) $company->getKey());
        $state = [];

        foreach (array_keys($templates->definitions()) as $key) {
            $state[self::field($key, 'subject')] = $rows[$key]['subject'] ?? null;
            $state[self::field($key, 'body')] = $rows[$key]['body'] ?? null;
        }

        $state['custom'] = $templates->customTemplates((int) $company->getKey())
            ->map(fn ($row): array => ['id' => $row->getKey(), 'name' => $row->name, 'subject' => $row->subject, 'body' => $row->body])
            ->values()
            ->all();

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $templates = app(EmailTemplateService::class);

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('email_templates')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('Order emails'))
                            ->icon(Heroicon::OutlinedTruck)
                            ->schema([
                                Text::make(__('Mailed to the customer when their order is placed and when it reaches a stage. A message you leave empty sends nothing, so write only the ones you want. Not sent for imported past orders, reseller store orders, or customers with no email address.')),
                                ...$this->sectionsFor($templates, EmailTemplateService::GROUP_ORDER),
                            ]),
                        Tab::make(__('Codes & password reset'))
                            ->icon(Heroicon::OutlinedKey)
                            ->schema([
                                Text::make(__('The one-time codes and the reset link. Leave a message empty to send the built-in plain mail shown in grey. A message you write is sent in the branded layout; it must keep the code or the link.')),
                                ...$this->sectionsFor($templates, EmailTemplateService::GROUP_CODE),
                            ]),
                        Tab::make(__('Investor emails'))
                            ->icon(Heroicon::OutlinedBriefcase)
                            ->schema([
                                Text::make(__('IMS v2 emails to investors. The portal always shows the news; an email goes out only for the news you switch on in the IMS office (IMS settings). Leave a message empty to send the built-in mail shown in grey.')),
                                ...$this->sectionsFor($templates, EmailTemplateService::GROUP_IMS),
                            ]),
                        Tab::make(__('My templates'))
                            ->icon(Heroicon::OutlinedPencilSquare)
                            ->schema([
                                Text::make(__('Make your own emails, for example an apology for a late parcel or a thank-you. Staff send one to an order\'s customer with the button "Email the customer" on the order.')),
                                Repeater::make('custom')
                                    ->hiddenLabel()
                                    ->columnSpanFull()
                                    ->schema([
                                        Hidden::make('id'),
                                        TextInput::make('name')->label(__('Template name (only you see it)'))->required()->maxLength(120),
                                        TextInput::make('subject')->label(__('Subject'))->required()->maxLength(EmailTemplateService::MAX_SUBJECT),
                                        Textarea::make('body')
                                            ->label(__('Message'))
                                            ->required()
                                            ->rows(8)
                                            ->maxLength(EmailTemplateService::MAX_BODY)
                                            ->helperText($this->placeholderHelp($templates->orderPlaceholders(), false))
                                            ->hintActions($this->toolsFor('custom', $templates->orderPlaceholders(), 'subject', 'body', null)),
                                    ])
                                    ->itemLabel(fn (array $state): string => filled($state['name'] ?? null) ? (string) $state['name'] : __('New template'))
                                    ->collapsed()
                                    ->reorderable(false)
                                    ->addActionLabel(__('Add a template')),
                            ]),
                    ]),
            ]);
    }

    /** @return list<Section> one collapsed section for each message of the group */
    protected function sectionsFor(EmailTemplateService $templates, string $group): array
    {
        $sections = [];

        foreach ($templates->definitionsInGroup($group) as $key => $definition) {
            $subject = self::field($key, 'subject');
            $body = self::field($key, 'body');
            $builtIn = $definition['default_body'] !== null;

            $sections[] = Section::make($definition['label'])
                ->collapsible()
                ->collapsed()
                ->compact()
                ->schema([
                    TextInput::make($subject)
                        ->label(__('Subject'))
                        ->placeholder($definition['default_subject'])
                        ->maxLength(EmailTemplateService::MAX_SUBJECT),
                    Textarea::make($body)
                        ->label(__('Message'))
                        ->rows(8)
                        ->placeholder($definition['default_body'])
                        ->maxLength(EmailTemplateService::MAX_BODY)
                        ->helperText($this->placeholderHelp($definition['placeholders'], $builtIn))
                        ->hintActions($this->toolsFor($key, $definition['placeholders'], $subject, $body, $definition['suggested'])),
                ]);
        }

        return $sections;
    }

    /**
     * The three buttons beside a message: use the suggested wording, preview it, send a test.
     *
     * @param  array<string, string>  $placeholders
     * @param  ?array{0: string, 1: string}  $suggested
     * @return list<Action>
     */
    protected function toolsFor(string $key, array $placeholders, string $subjectField, string $bodyField, ?array $suggested): array
    {
        $actions = [];

        if ($suggested !== null) {
            $actions[] = Action::make('suggest_'.$bodyField)
                ->label(__('Use suggested wording'))
                ->action(function (Set $set) use ($subjectField, $bodyField, $suggested): void {
                    $set($subjectField, $suggested[0]);
                    $set($bodyField, $suggested[1]);
                });
        }

        $actions[] = Action::make('preview_'.$bodyField)
            ->label(__('Preview'))
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading(__('Preview'))
            ->modalDescription(__('Example values are filled in. Nothing is sent.'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalContent(function (Get $get) use ($key, $placeholders, $subjectField, $bodyField) {
                $preview = $this->company() ? app(EmailTemplateService::class)->preview((int) $this->company()->getKey(), $key, $get($subjectField), $get($bodyField), $placeholders) : null;

                return View::make('filament.pages.email-template-preview', ['preview' => $preview]);
            });

        $actions[] = Action::make('test_'.$bodyField)
            ->label(__('Send a test'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading(__('Send a test email'))
            ->modalDescription(__('Sends this message with example values to the address below, through this company\'s email account.'))
            ->modalSubmitActionLabel(__('Send'))
            ->schema([
                TextInput::make('email')->label(__('Send to'))->email()->required()->default(fn (): ?string => Auth::user()?->email),
            ])
            ->action(function (array $data, Get $get) use ($key, $placeholders, $subjectField, $bodyField): void {
                $this->sendTest($key, $placeholders, $get($subjectField), $get($bodyField), (string) $data['email']);
            });

        return $actions;
    }

    /** @param array<string, string> $placeholders */
    protected function placeholderHelp(array $placeholders, bool $hasBuiltIn): string
    {
        $list = collect($placeholders)
            ->map(fn (string $label, string $name): string => ":{$name} = {$label}")
            ->implode('; ');

        return __('You can use: :placeholders.', ['placeholders' => $list])
            .' '.($hasBuiltIn ? __('Leave empty to send the built-in mail.') : __('Leave empty to send nothing.'));
    }

    /** @param array<string, string> $placeholders */
    protected function sendTest(string $key, array $placeholders, ?string $subject, ?string $body, string $email): void
    {
        $company = $this->company();

        abort_unless($company !== null && static::canAccess(), 404);

        $companyId = (int) $company->getKey();
        $templates = app(EmailTemplateService::class);
        $setting = StorefrontSetting::primaryFor($companyId);
        $notifications = app(StorefrontNotificationService::class);

        if ($setting === null || ! $notifications->emailDeliveryAvailable($setting)) {
            Notification::make()->title(__('No email account is set up'))->body(__('Set it up in Settings → Integrations → Email Setup, then try again.'))->warning()->send();

            return;
        }

        $values = $templates->sampleValues($placeholders, $templates->brand($companyId)['name']);
        $row = new EmailTemplate(['company_id' => $companyId, 'key' => $key, 'subject' => $subject, 'body' => filled($body) ? $body : ($templates->definitions()[$key]['default_body'] ?? '')]);
        $message = $templates->render($companyId, $key, $values, $row);

        if ($message === null) {
            Notification::make()->title(__('Write the message first'))->warning()->send();

            return;
        }

        if ($notifications->sendMailable($setting, $email, $templates->wrap($companyId, $message), EmailLog::KIND_TEST)) {
            Notification::make()->title(__('Test email sent'))->body(__('Check the inbox of :email.', ['email' => $email]))->success()->send();
        } else {
            Notification::make()->title(__('The test email could not be sent'))->body(__('The reason is in Settings → Logs → Email.'))->danger()->send();
        }
    }

    public function save(): void
    {
        $company = $this->company();

        abort_unless($company !== null && static::canAccess(), 404);

        $companyId = (int) $company->getKey();
        $templates = app(EmailTemplateService::class);
        $data = $this->form->getState();
        $written = [];
        $problems = [];

        foreach (array_keys($templates->definitions()) as $key) {
            $subject = $data[self::field($key, 'subject')] ?? null;
            $body = $data[self::field($key, 'body')] ?? null;
            $written[$key] = ['subject' => $subject, 'body' => $body];

            if ($problem = $templates->problem($key, $subject, $body)) {
                $problems['data.'.self::field($key, 'body')] = $problem;
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $before = ['built_in' => $templates->rows($companyId), 'custom' => $templates->customTemplates($companyId)->pluck('name')->all()];

        $templates->save($companyId, $written);
        $templates->saveCustom($companyId, array_values((array) ($data['custom'] ?? [])));

        app(AuditLogService::class)->record('email_templates.saved', $company, $before, ['built_in' => $templates->rows($companyId), 'custom' => $templates->customTemplates($companyId)->pluck('name')->all()]);

        Notification::make()->title(__('Email templates saved'))->success()->send();

        $this->mount();
    }
}
