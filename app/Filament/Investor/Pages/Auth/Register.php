<?php

namespace App\Filament\Investor\Pages\Auth;

use App\Models\AgreementTemplate;
use App\Models\ChannelPartner;
use App\Models\InvestmentDocument;
use App\Services\AgreementService;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\InvestorOnboardingService;
use App\Support\ImsPassword;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Self sign-up through a channel partner's invite link
 * (/investor/register?code=…; 15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md
 * Phase 3). Without a valid code the page only says to ask for a link. The
 * application waits in Investor Drafts; the applicant can sign in and see
 * its status. Money and the paper deed are still handled at the office.
 */
class Register extends BaseRegister
{
    #[Url]
    public ?string $code = null;

    public function partner(): ?ChannelPartner
    {
        return ChannelPartner::forInviteCode($this->code);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Apply to invest');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Apply to invest');
    }

    public function getSubheading(): string|Htmlable|null
    {
        $partner = $this->partner();

        return $partner ? __('Invited by :name. The company office reviews every application before your account opens.', ['name' => $partner->name]) : null;
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->partner()) {
            return $schema->components([
                Text::make(__('Sign-up works only through an invite link from a channel partner of the company. Please ask your channel partner or the company office for one.')),
            ]);
        }

        return parent::content($schema);
    }

    public function form(Schema $schema): Schema
    {
        $agreement = AgreementTemplate::current(AgreementTemplate::KIND_INVESTOR);

        return $schema->components([
            Section::make(__('Your details'))->schema([
                TextInput::make('name')->label(__('Full name (as on NID)'))->required()->maxLength(255),
                TextInput::make('guardian_name')->label(__('Father / spouse name'))->maxLength(255),
                DatePicker::make('date_of_birth')->label(__('Date of birth'))->maxDate(now()),
                TextInput::make('phone')->label(__('Phone'))->tel()->required()->maxLength(40),
                TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
                TextInput::make('nid_number')->label(__('NID / passport number'))->required()->maxLength(40),
                Textarea::make('address')->label(__('Address'))->rows(2)->maxLength(1000),
            ]),
            Section::make(__('Nominee'))->schema([
                TextInput::make('nominee_name')->label(__('Nominee name'))->maxLength(255),
                TextInput::make('nominee_relation')->label(__('Relation to you'))->maxLength(100),
                TextInput::make('nominee_phone')->label(__('Nominee phone'))->tel()->maxLength(40),
                TextInput::make('nominee_nid_or_passport')->label(__('Nominee NID / passport number'))->maxLength(40),
            ]),
            Section::make(__('Documents'))->schema([
                $this->upload('nid_files', __('NID / passport copy (both sides)'))->required()->maxFiles(2),
                $this->upload('photo_files', __('Your photo'))->required()->maxFiles(1)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp']),
                $this->upload('nominee_nid_files', __('Nominee NID / passport copy'))->maxFiles(2),
            ]),
            Section::make(__('Password'))->schema([
                TextInput::make('password')->label(__('Password'))->password()->revealable()->autocomplete('new-password')->required()->rule(ImsPassword::rule())->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')->label(__('Confirm password'))->password()->revealable()->autocomplete('new-password')->required()->dehydrated(false),
            ]),
            Checkbox::make('accept_agreement')
                ->label(fn (): string => __('I have read and agree to the :title.', ['title' => (string) $agreement?->title]))
                ->helperText(__('You can read it again any time under Agreements after signing in.'))
                ->accepted()
                ->visible($agreement !== null),
            Text::make(fn (): string => (string) $agreement?->body)->visible($agreement !== null)->extraAttributes(['class' => 'max-h-64 overflow-y-auto whitespace-pre-line text-sm']),
        ]);
    }

    protected function upload(string $name, string $label): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->multiple()
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(5120)
            ->disk(fn (): string => app(CompanyStorageService::class)->privateDiskName())
            ->directory(fn (): string => app(CompanyStorageService::class)->privateDirectory(app(CompanyContext::class)->company(), 'investment-documents'))
            ->visibility('private')
            ->previewable(false)
            ->openable(false);
    }

    public function register(): ?RegistrationResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $partner = $this->partner();
        $company = app(CompanyContext::class)->company();
        abort_unless($partner !== null && $company !== null, 404);

        $data = $this->form->getState();

        $account = app(InvestorOnboardingService::class)->register($company, $partner, $data, array_filter([
            InvestmentDocument::CATEGORY_INVESTOR_NID => array_values((array) ($data['nid_files'] ?? [])),
            InvestmentDocument::CATEGORY_INVESTOR_PHOTO => array_values((array) ($data['photo_files'] ?? [])),
            InvestmentDocument::CATEGORY_NOMINEE_NID => array_values((array) ($data['nominee_nid_files'] ?? [])),
        ]));

        if (($data['accept_agreement'] ?? false) && ($agreement = AgreementTemplate::current(AgreementTemplate::KIND_INVESTOR))) {
            app(AgreementService::class)->accept($account, $agreement);
        }

        Filament::auth()->login($account);
        session()->regenerate();

        return app(RegistrationResponse::class);
    }
}
