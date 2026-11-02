<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Company;
use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\Email\EmailProvider;
use App\Services\Email\EmailUsageService;
use App\Services\StorefrontNotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Settings → Email inbox (owner request 2026-11-02, the approved "Email settings" design): a mailbox's connection (how mail
 * reaches the ERP: the forwarding address, shown once, a new one on request, a test mail), what it sends through, its
 * signature, how the inbox behaves (undo window, matching mail to customers, pictures, alerts) and the company's labels.
 * One page per company; the mailbox shown is picked at the top. Everything re-checks who is asking: Settings access and the
 * company, and only this company's mailboxes, sites and labels are ever read or changed.
 */
class EmailInboxSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'email-inbox';

    protected string $view = 'filament.pages.email-inbox-settings';

    #[Url(as: 'mailbox')]
    public ?int $mailboxId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public ?int $companyId = null;

    /** The mailbox's forwarding address; it exists only right after it was made, because only its hash is kept. */
    public ?string $forwardingUrl = null;

    public string $labelName = '';

    public string $labelColor = 'blue';

    public ?int $editingLabelId = null;

    public static function getNavigationLabel(): string
    {
        return __('Email inbox');
    }

    public function getTitle(): string
    {
        return __('Email inbox settings');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        $company = app(CompanyContext::class)->company();

        return (bool) ($user?->canManageSettings() && $company && $user->canAccessCompany((int) $company->getKey()));
    }

    public function mount(): void
    {
        $this->companyId = (int) $this->company()->getKey();
        $this->mailboxId = $this->mailbox()?->getKey();
        $this->fillForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addMailbox')
                ->label(__('Add mailbox'))
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray')
                ->modalHeading(__('Add mailbox'))
                ->modalDescription(__('A mailbox is an email address this company reads in CRM → Email.'))
                ->modalSubmitActionLabel(__('Add mailbox'))
                ->schema(fn (): array => [
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(120)->placeholder('ZamZam Gadget'),
                    TextInput::make('address')->label(__('Email address'))->email()->required()->maxLength(190)
                        ->rule(fn (): \Closure => $this->addressIsFree())
                        ->validationMessages(['unique' => __('This company already has a mailbox with that address.')]),
                    Select::make('storefront_setting_id')->label(__('Sends through the email account of'))
                        ->options(fn (): array => $this->siteOptions())->placeholder(__('The company\'s main site')),
                ])
                ->action(function (array $data): void {
                    $company = $this->company();
                    $mailbox = EmailMailbox::query()->create([
                        'company_id' => $company->getKey(),
                        'name' => trim((string) $data['name']),
                        'address' => mb_strtolower(trim((string) $data['address'])),
                        'storefront_setting_id' => $this->ownSiteId($data['storefront_setting_id'] ?? null),
                        'receive_mode' => EmailMailbox::RECEIVE_WEBHOOK,
                        'is_active' => true,
                    ]);

                    $this->mailboxId = $mailbox->getKey();
                    $this->forwardingUrl = $mailbox->webhookUrl($mailbox->issueToken());
                    unset($this->mailboxes, $this->mailbox);
                    $this->fillForm();

                    Notification::make()->title(__('Mailbox added. Copy its forwarding address now.'))->success()->send();
                }),
        ];
    }

    // --- The mailboxes ----------------------------------------------------------------------------------

    /** @return Collection<int, EmailMailbox> */
    #[Computed]
    public function mailboxes(): Collection
    {
        return EmailMailbox::query()->where('company_id', $this->company()->getKey())->orderBy('id')->get();
    }

    #[Computed]
    public function mailbox(): ?EmailMailbox
    {
        return $this->mailboxes->firstWhere('id', $this->mailboxId) ?? $this->mailboxes->first();
    }

    public function updatedMailboxId(): void
    {
        $this->forwardingUrl = null;
        unset($this->mailbox);
        $this->fillForm();
    }

    // --- The form ---------------------------------------------------------------------------------------

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Mailbox'))->columns(2)->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(120),
                    TextInput::make('address')->label(__('Email address'))->email()->required()->maxLength(190)
                        ->rule(fn (): \Closure => $this->addressIsFree(editing: true))
                        ->validationMessages(['unique' => __('This company already has a mailbox with that address.')]),
                    Toggle::make('is_active')->label(__('Mailbox is on'))->helperText(__('Off: mail sent to it is refused, and nothing can be sent from it.')),
                ]),
                Section::make(__('Receiving'))->description(__('How mail sent to this address reaches the ERP.'))->schema([
                    Select::make('receive_mode')->label(__('How mail arrives'))->selectablePlaceholder(false)->options([
                        EmailMailbox::RECEIVE_WEBHOOK => __('Instant forwarding (Cloudflare Email Routing)'),
                        EmailMailbox::RECEIVE_NONE => __('Send only (this address sends mail but has no inbox)'),
                    ])->helperText(__('Cloudflare Email Routing pushes each mail to the ERP the moment it arrives. No mailbox password is stored here.')),
                ]),
                Section::make(__('Sending'))->description(__('Mail leaves through the email account set up in Integrations → Email Setup.'))->schema([
                    Select::make('storefront_setting_id')->label(__('Sends through the email account of'))
                        ->options(fn (): array => $this->siteOptions())->placeholder(__('The company\'s main site')),
                ]),
                Section::make(__('Signature'))->description(__('Added to new messages and replies.'))->schema([
                    Textarea::make('signature')->label(__('Signature'))->rows(4)->maxLength(2000)->placeholder("Sadia, ZamZam Gadget\n01XXXXXXXXX · zamzamgadgetbd.com"),
                    Toggle::make('signature_on_replies')->label(__('Add it to replies too')),
                ]),
                Section::make(__('Inbox behaviour'))->description(__('Applies to everyone who uses this mailbox.'))->columns(2)->schema([
                    Select::make('undo_send_seconds')->label(__('Undo send'))->selectablePlaceholder(false)->options([
                        0 => __('Off (send at once)'),
                        5 => __(':count seconds', ['count' => 5]),
                        10 => __(':count seconds', ['count' => 10]),
                        20 => __(':count seconds', ['count' => 20]),
                        30 => __(':count seconds', ['count' => 30]),
                        60 => __(':count seconds', ['count' => 60]),
                    ])->helperText(__('Time to take a message back after pressing Send.')),
                    Toggle::make('match_customers')->label(__('Match mail to customers'))->helperText(__('By the sender\'s email address.')),
                    Toggle::make('load_remote_images')->label(__('Load pictures from the internet'))->helperText(__('Off keeps tracking pictures out. A picture can still be shown per message.')),
                    Toggle::make('notify')->label(__('Alert staff on new mail'))->helperText(__('Uses the ERP bell and push notifications. A conversation given to someone alerts only them.')),
                ]),
            ]);
    }

    protected function fillForm(): void
    {
        $mailbox = $this->mailbox();

        $this->form->fill($mailbox === null ? [] : [
            'name' => $mailbox->name,
            'address' => $mailbox->address,
            'is_active' => (bool) $mailbox->is_active,
            'receive_mode' => $mailbox->receive_mode,
            'storefront_setting_id' => $mailbox->storefront_setting_id,
            'signature' => (string) $mailbox->signature,
            'signature_on_replies' => (bool) $mailbox->setting('signature_on_replies'),
            'undo_send_seconds' => (int) $mailbox->setting('undo_send_seconds'),
            'match_customers' => (bool) $mailbox->setting('match_customers'),
            'load_remote_images' => (bool) $mailbox->setting('load_remote_images'),
            'notify' => (bool) $mailbox->setting('notify'),
        ]);
    }

    public function save(): void
    {
        $this->company();
        $mailbox = $this->mailbox();

        if ($mailbox === null) {
            return;
        }

        $state = $this->form->getState();

        $mailbox->forceFill([
            'name' => trim((string) $state['name']),
            'address' => mb_strtolower(trim((string) $state['address'])),
            'is_active' => (bool) $state['is_active'],
            'receive_mode' => in_array($state['receive_mode'], [EmailMailbox::RECEIVE_WEBHOOK, EmailMailbox::RECEIVE_NONE], true) ? $state['receive_mode'] : EmailMailbox::RECEIVE_WEBHOOK,
            'storefront_setting_id' => $this->ownSiteId($state['storefront_setting_id'] ?? null),
            'signature' => trim((string) ($state['signature'] ?? '')) ?: null,
            'settings' => [
                ...((array) $mailbox->settings),
                'signature_on_replies' => (bool) $state['signature_on_replies'],
                'undo_send_seconds' => max(0, min(60, (int) $state['undo_send_seconds'])),
                'match_customers' => (bool) $state['match_customers'],
                'load_remote_images' => (bool) $state['load_remote_images'],
                'notify' => (bool) $state['notify'],
            ],
        ])->save();

        unset($this->mailboxes, $this->mailbox);

        Notification::make()->title(__('Changes saved'))->success()->send();
    }

    // --- Connection -------------------------------------------------------------------------------------

    /** A new forwarding address: the old one stops working at once. */
    public function newToken(): void
    {
        $this->company();
        $mailbox = $this->mailbox();

        if ($mailbox === null) {
            return;
        }

        $this->forwardingUrl = $mailbox->webhookUrl($mailbox->issueToken());
        unset($this->mailbox);

        Notification::make()->title(__('New forwarding address made. The old one no longer works.'))->success()->send();
    }

    /** Sends a short mail from the site's email account to this mailbox's address: it should come back into the inbox. */
    public function sendTestMail(): void
    {
        $this->company();
        $mailbox = $this->mailbox();
        $site = $mailbox === null ? null : $this->sendingSite($mailbox);

        if ($mailbox === null) {
            return;
        }

        if ($site === null) {
            Notification::make()->title(__('This company has no site yet, so there is no email account to send through.'))->warning()->send();

            return;
        }

        $error = app(StorefrontNotificationService::class)->sendTestEmail($site, $mailbox->address);

        if ($error === null) {
            Notification::make()->title(__('Test mail sent to :address. It should appear in CRM → Email in a few seconds.', ['address' => $mailbox->address]))->success()->send();
        } else {
            Notification::make()->title(__('The test mail was not sent: :reason', ['reason' => $error]))->danger()->send();
        }
    }

    /** The email account this mailbox sends through, described. @return array<string, mixed> */
    #[Computed]
    public function sending(): array
    {
        $mailbox = $this->mailbox();
        $site = $mailbox === null ? null : $this->sendingSite($mailbox);

        if ($site === null) {
            return ['ready' => false, 'site' => null];
        }

        $provider = EmailProvider::forSetting($site);
        $notifier = app(StorefrontNotificationService::class);
        $usage = app(EmailUsageService::class)->report((int) $this->companyId, $provider->via, 0, EmailUsageService::settingsFor($site));

        return [
            'ready' => $notifier->emailDeliveryAvailable($site),
            'site' => $site,
            'provider' => $provider,
            'port' => data_get($site->notification_credentials, 'mail_port'),
            'from' => $notifier->mailFromAddress($site),
            'sent' => (int) ($usage['totals']['sent'] ?? 0),
            'failed' => (int) ($usage['totals']['failed'] ?? 0),
            'setupUrl' => Integrations::getUrl(),
            'deliveryUrl' => EmailDelivery::getUrl(),
        ];
    }

    // --- Labels -----------------------------------------------------------------------------------------

    /** @return Collection<int, EmailLabel> */
    #[Computed]
    public function labels(): Collection
    {
        $this->company();

        return EmailLabel::query()->where('company_id', $this->companyId)->withCount('threads')->orderBy('name')->get();
    }

    public function saveLabel(): void
    {
        $this->company();
        $this->labelName = trim((string) preg_replace('/\s+/u', ' ', $this->labelName));

        $this->validate([
            'labelName' => ['required', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail): void {
                if (EmailLabel::query()->where('company_id', $this->companyId)->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                    ->when($this->editingLabelId, fn ($query) => $query->whereKeyNot($this->editingLabelId))->exists()) {
                    $fail(__('There is already a label with that name.'));
                }
            }],
            'labelColor' => ['required', Rule::in(EmailLabel::COLORS)],
        ], [], ['labelName' => __('Label name')]);

        if ($this->editingLabelId !== null) {
            EmailLabel::query()->where('company_id', $this->companyId)->whereKey($this->editingLabelId)->update(['name' => $this->labelName, 'color' => $this->labelColor]);
        } else {
            EmailLabel::query()->create(['company_id' => $this->companyId, 'name' => $this->labelName, 'color' => $this->labelColor]);
        }

        $this->cancelLabel();
        unset($this->labels);
    }

    public function editLabel(int $labelId): void
    {
        $this->company();
        $label = EmailLabel::query()->where('company_id', $this->companyId)->find($labelId);

        if ($label !== null) {
            $this->editingLabelId = $label->getKey();
            $this->labelName = $label->name;
            $this->labelColor = $label->color;
            $this->resetErrorBag();
        }
    }

    public function cancelLabel(): void
    {
        $this->editingLabelId = null;
        $this->labelName = '';
        $this->labelColor = 'blue';
        $this->resetErrorBag();
    }

    /** Deletes the label, and takes it off its conversations; the mail itself is untouched. */
    public function deleteLabel(int $labelId): void
    {
        $this->company();
        $label = EmailLabel::query()->where('company_id', $this->companyId)->find($labelId);

        if ($label !== null) {
            $label->threads()->detach();
            $label->delete();
            unset($this->labels);
        }
    }

    // --- Helpers ----------------------------------------------------------------------------------------

    /** The current company, after re-checking who is asking (every handler starts here). */
    protected function company(): Company
    {
        $company = app(CompanyContext::class)->company();

        abort_unless($company !== null, 404, 'Select a company before opening Email inbox settings.');

        if ($this->companyId !== null) {
            abort_unless((int) $company->getKey() === $this->companyId, 409, 'The selected company changed. Reload Email inbox settings before continuing.');
        }

        $user = Auth::user();
        abort_unless($user?->canManageSettings() && $user->canAccessCompany((int) $company->getKey()), 403);

        return $company;
    }

    /** A rule: no other mailbox of this company has the address (whatever its case; addresses are kept in lower case). */
    protected function addressIsFree(bool $editing = false): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($editing): void {
            $taken = EmailMailbox::query()
                ->where('company_id', $this->companyId)
                ->whereRaw('LOWER(address) = ?', [mb_strtolower(trim((string) $value))])
                ->when($editing && $this->mailbox() !== null, fn ($query) => $query->whereKeyNot($this->mailbox()->getKey()))
                ->exists();

            if ($taken) {
                $fail(__('This company already has a mailbox with that address.'));
            }
        };
    }

    /** @return array<int, string> */
    protected function siteOptions(): array
    {
        return StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->orderBy('site_type')->orderBy('id')->get()
            ->mapWithKeys(fn (StorefrontSetting $row): array => [$row->getKey() => $row->siteTypeLabel().' · '.($row->siteDomain() ?: '—')])->all();
    }

    /** Only one of this company's sites; anything else means "the main site". */
    protected function ownSiteId(mixed $id): ?int
    {
        return filled($id) && StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->whereKey((int) $id)->exists() ? (int) $id : null;
    }

    protected function sendingSite(EmailMailbox $mailbox): ?StorefrontSetting
    {
        return $mailbox->storefront_setting_id
            ? StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->find($mailbox->storefront_setting_id)
            : StorefrontSetting::primaryFor($this->companyId);
    }

    /** The Worker script of docs/email-inbox/, to copy into Cloudflare. */
    public function workerScript(): string
    {
        $path = base_path('docs/email-inbox/cloudflare-email-worker.js');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
